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

class cli_minutely implements cliCommand
{
	public function getDescription()
	{
		return "Tasks that needs to run every minute. |g|Usage: minutely <task>";
	}

	public function getAvailMethods()
	{
		return "killsLastHour fileCacheClean all"; // Space seperated list
	}

	public function getCronInfo()
	{
		return array(
			60 => "all"
		);
	}

	public function execute($parameters, $db)
	{
		global $base;
		chdir($base);
		if (sizeof($parameters) == 0 || $parameters[0] == "") CLI::out("Usage: |g|help <command>|n| To see a list of commands, use: |g|list", true);
		$command = $parameters[0];

		switch($command)
		{
			case "all":
				// Cleanup old sessions
				$db->execute("delete from zz_users_sessions where validTill < now()");

				$killsLastHour = $db->queryField("select count(*) count from zz_killmails where insertTime > date_sub(now(), interval 1 hour)", "count");
				Storage::store("KillsLastHour", $killsLastHour);
				$db->execute("delete from zz_analytics where dttm < date_sub(now(), interval 1 hour)");

				$fc = new FileCache("$base/cache/queryCache/");
				$fc->cleanUp();
			break;

			case "killsLastHour":
				$killsLastHour = $db->queryField("select count(*) count from zz_killmails where insertTime > date_sub(now(), interval 1 hour)", "count");
				Storage::store("KillsLastHour", $killsLastHour);
			break;

			case "fileCacheClean";
				$fc = new FileCache;
				$fc->cleanUp();
			break;
		}
	}
}
