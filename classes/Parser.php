<?php
/* zKillboard
 * Copyright (C) 2012-2013 EVE-KILL Team and EVSCO.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Parser for raw killmails from ingame EVE.
 */

class Parser
{
	public static function parseKills()
	{
		if (Util::isMaintenanceMode()) return;

		$timer = new Timer();

		$maxTime = 65 * 1000 ;

		Db::execute("set session wait_timeout = 120000");
		Db::execute("create temporary table if not exists zz_participants_temporary select * from zz_participants where 1 = 0");

		$numKills = 0;

		while ($timer->stop() < $maxTime) {
			if (Util::isMaintenanceMode()) {
				self::removeTempTables();
				return;
			}
			Db::execute("delete from zz_participants_temporary");

			//Log::log("Fetching kills for processing...");
			//$ids = Db::query("select killID from zz_killmails where processed = 0 order by killID desc limit 1", array(), 0);
			$ids = Db::query("select max(killID) killID from zz_killmails where processed = 0", array(), 0);
			$result = array();
			foreach($ids as $row) {
				$killID = $row["killID"];
				$result[] = Db::queryRow("select * from zz_killmails where killID = :killID", array(":killID" => $killID), 0);
			}

			if (sizeof($result) == 0) {
				$currentSecond = (int) date("s");
				$sleepTime = max(1, 15 - ($currentSecond % 15));
				sleep($sleepTime);
				continue;
			}

			//Log::log("Processing fetched kills...");
			$processedKills = array();
			$cleanupKills = array();
			foreach ($result as $row) {
				$numKills++;
				$kill = json_decode(Killmail::get($row["killID"]), true);
				Db::execute("delete from zz_killid where killID = :killID", array(":killID" => $row["killID"]));
				if (!isset($kill["killID"])) {
					Log::log("Problem with kill " . $row["killID"]);
					Db::execute("update zz_killmails set processed = 2 where killid = :killid", array(":killid" => $row["killID"]));
					continue;
				}
				$killID = $kill["killID"];

				// Because of CREST caching and the want for accurate prices, don't process the first hour
				// of kills until after 01:05 each day
				if (date("Gi") < 105) return;

				// Cleanup if we're reparsing
				$cleanupKills[] = $killID;

				// Do some validation on the kill
				if (!self::validKill($kill)) {
					Db::execute("update zz_killmails set processed = 3 where killid = :killid", array(":killid" => $row["killID"]));
					continue;
				}

				$totalCost = 0;
				$itemInsertOrder = 0;

				$totalCost += self::processItems($kill, $killID, $kill["items"], $itemInsertOrder);
				$totalCost += self::processVictim($kill, $killID, $kill["victim"], false);
				foreach ($kill["attackers"] as $attacker) self::processAttacker($kill, $killID, $attacker, $kill["victim"]["shipTypeID"], $totalCost);
				$points = Points::calculatePoints($killID, true);
				Db::execute("update zz_participants_temporary set points = :points, number_involved = :numI, total_price = :tp where killID = :killID", array(":killID" => $killID, ":points" => $points, ":numI" => sizeof($kill["attackers"]), ":tp" => $totalCost));

				$processedKills[] = $killID;
			}

			if (sizeof($cleanupKills)) {
				Db::execute("delete from zz_participants where killID in (" . implode(",", $cleanupKills) . ")");
			}
			Db::execute("insert into zz_participants select * from zz_participants_temporary");
			if (sizeof($processedKills)) {
				Db::execute("insert ignore into zz_stats_queue values (" . implode("), (", $processedKills) . ")");
				Db::execute("update zz_killmails set processed = 1 where killID in (" . implode(",", $processedKills) . ")");
			}
		}
		if ($numKills > 0)
		{
			Log::log("Processed $numKills kills");
		}
		self::removeTempTables();
	}

	private static function removeTempTables()
	{
		Db::execute("drop table if exists zz_participants_temporary");
	}

	private static function validKill(&$kill)
	{
		$victimCorp = $kill["victim"]["corporationID"] < 1000999 ? 0 : $kill["victim"]["corporationID"];
		$victimAlli = $kill["victim"]["allianceID"];

		$npcOnly = true;
		$blueOnBlue = true;
		foreach ($kill["attackers"] as $attacker) {
			$attackerGroupID = Info::getGroupID($attacker["shipTypeID"]);
			if ($attackerGroupID == 365) return true; // A tower is involved

			// Don't process the kill if it's NPC only
			$npcOnly &= $attacker["characterID"] == 0 && ($attacker["corporationID"] < 1999999 && $attacker["corporationID"] != 1000125);

			// Check for blue on blue
			if ($attacker["characterID"] != 0) $blueOnBlue &= $victimCorp == $attacker["corporationID"] && $victimAlli == $attacker["allianceID"];
		}
		if ($npcOnly /*|| $blueOnBlue*/) return false;

		return true;
	}

	/**
	 * @param boolean $isNpcVictim
	 */
	private static function processVictim(&$kill, $killID, &$victim, $isNpcVictim)
	{
		$dttm = (string) $kill["killTime"];

		$shipPrice = Price::getItemPrice($victim["shipTypeID"], $dttm, true);
		$groupID = Info::getGroupID($victim["shipTypeID"]);
		$regionID = Info::getRegionIDFromSystemID($kill["solarSystemID"]);

		if (!$isNpcVictim) Db::execute("
				insert into zz_participants_temporary
				(killID, solarSystemID, regionID, isVictim, shipTypeID, groupID, shipPrice, damage, factionID, allianceID,
				 corporationID, characterID, dttm, vGroupID)
				values
				(:killID, :solarSystemID, :regionID, 1, :shipTypeID, :groupID, :shipPrice, :damageTaken, :factionID, :allianceID,
				 :corporationID, :characterID, :dttm, :vGroupID)",
				(array(
					   ":killID" => $killID,
					   ":solarSystemID" => $kill["solarSystemID"],
					   ":regionID" => $regionID,
					   ":shipTypeID" => $victim["shipTypeID"],
					   ":groupID" => $groupID,
					   ":vGroupID" => $groupID,
					   ":shipPrice" => $shipPrice,
					   ":damageTaken" => $victim["damageTaken"],
					   ":factionID" => $victim["factionID"],
					   ":allianceID" => $victim["allianceID"],
					   ":corporationID" => $victim["corporationID"],
					   ":characterID" => $victim["characterID"],
					   ":dttm" => $dttm,
					  )));

		Info::addChar($victim["characterID"], $victim["characterName"]);
		Info::addCorp($victim["corporationID"], $victim["corporationName"]);
		Info::addAlli($victim["allianceID"], $victim["allianceName"]);

		return $shipPrice;
	}

	private static function processAttacker(&$kill, &$killID, &$attacker, $victimShipTypeID, $totalCost)
	{
		$victimGroupID = Info::getGroupID($victimShipTypeID);
		$attackerGroupID = Info::getGroupID($attacker["shipTypeID"]);
		$regionID = Info::getRegionIDFromSystemID($kill["solarSystemID"]);

		$dttm = (string) $kill["killTime"];

		Db::execute("
				insert into zz_participants_temporary
				(killID, solarSystemID, regionID, isVictim, characterID, corporationID, allianceID, total_price, vGroupID,
				 factionID, damage, finalBlow, weaponTypeID, shipTypeID, groupID, dttm)
				values
				(:killID, :solarSystemID, :regionID, 0, :characterID, :corporationID, :allianceID, :total, :vGroupID,
				 :factionID, :damageDone, :finalBlow, :weaponTypeID, :shipTypeID, :groupID, :dttm)",
				(array(
					   ":killID" => $killID,
					   ":solarSystemID" => $kill["solarSystemID"],
					   ":regionID" => $regionID,
					   ":characterID" => $attacker["characterID"],
					   ":corporationID" => $attacker["corporationID"],
					   ":allianceID" => $attacker["allianceID"],
					   ":factionID" => $attacker["factionID"],
					   ":damageDone" => $attacker["damageDone"],
					   ":finalBlow" => $attacker["finalBlow"],
					   ":weaponTypeID" => $attacker["weaponTypeID"],
					   ":shipTypeID" => $attacker["shipTypeID"],
					   ":groupID" => $attackerGroupID,
					   ":dttm" => $dttm,
					   ":total" => $totalCost,
					   ":vGroupID" => $victimGroupID,
					  )));
		Info::addChar($attacker["characterID"], $attacker["characterName"]);
		Info::addCorp($attacker["corporationID"], $attacker["corporationName"]);
		Info::addAlli($attacker["allianceID"], $attacker["allianceName"]);
	}

	/**
	 * @param integer $itemInsertOrder
	 */
	private static function processItems(&$kill, &$killID, &$items, &$itemInsertOrder, $isCargo = false, $parentFlag = 0)
	{
		$totalCost = 0;
		foreach ($items as $item) {
			$totalCost += self::processItem($kill, $killID, $item, $itemInsertOrder++, $isCargo, $parentFlag);
			if (@is_array($item["items"])) {
				$itemContainerFlag = $item["flag"];
				$totalCost += self::processItems($kill, $killID, $item["items"], $itemInsertOrder, true, $itemContainerFlag);
			}
		}
		return $totalCost;
	}

	/**
	 * @param integer $itemInsertOrder
	 */
	private static function processItem(&$kill, &$killID, &$item, $itemInsertOrder, $isCargo = false, $parentContainerFlag = -1)
	{
		global $itemNames;

		$dttm = (string) $kill["killTime"];

		if ($itemNames == null ) {
			$itemNames = array();
			$results = Db::query("select typeID, typeName from ccp_invTypes", array(), 3600);
			foreach ($results as $row) {
				$itemNames[$row["typeID"]] = $row["typeName"];
			}
		}
		$typeID = $item["typeID"];
		if (isset($item["typeID"]) && isset($itemNames[$item["typeID"]])) $itemName = $itemNames[$item["typeID"]];
		else $itemName = "TypeID $typeID";

		if ($item["typeID"] == 33329 && $item["flag"] == 89) $price = 0.01; // Golden pod implant can't be destroyed
		else $price = Price::getItemPrice($typeID, $dttm, true);
		if ($isCargo && strpos($itemName, "Blueprint") !== false) $item["singleton"] = 2;
		if ($item["singleton"] == 2) {
			$price = $price / 100;
		}

		return ($price * ($item["qtyDropped"] + $item["qtyDestroyed"]));
	}
}
