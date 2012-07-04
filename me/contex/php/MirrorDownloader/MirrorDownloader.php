<?php
/*
 * This file is part of MirrorDownloader <http://www.contex.me/>.
 *
 * MirrorDownloader is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * MirrorDownloader is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
 
/*
 * This class contains all the functions that are related to the downloader.
 */
class MirrorDownloader {
	protected $name, $version, $mysqli, $prefix;
	private $file_id, $size, $downloads, $mirrors, $mirror = false, $setup = false; 
	
	/*
	* The class gets constructed by using new MirrorDownloader("Project name", "Project version");
	* The Project name should be the same for every new version.
	* Be sure to configure the MySQL options in the constructor.
	*/
	public function __construct($name, $version) {
		$this->name = $name;
		$this->version = $version;
		$this->prefix = ""; //Prefix of the database tables.
		$this->mysqli = new mysqli("localhost", "user", "password", "database");
		if (mysqli_connect_errno()) {
			printf("Connect failed: %s\n", mysqli_connect_error());
			exit();
		}
		$this->checkDatabase();
	}
	
	/*
	* Closes MySQLi once the class is destructed.
	*/
	public function __destruct() {
		mysqli_close($this->mysqli);
	}
	
	/*
	* This function is used to add mirrors to the downloader.
	* 		- name: The name of the mirror, for example: Website
	*		- url: The link/url to the file of the mirror
	*		- limit: The bandwith limit in GB (GigaBytes) of the hosting that the mirror is placed on. 
	*				 If you have a 500GB traffic/bandwith limit, put 500.
	*/
	public function addMirror($name, $url, $limit) {
		$this->mirrors[] = array('name' => $name, 'url' => $url, 'limit' => $limit);
	}
	
	/*
	* This functions initializes the mirrors and checks their status but also the size of the file.
	* It will insert missing mirrors into the database or grab any relevant data from the database.
	*/
	private function setup() {
		foreach ($this->mirrors as $key => $array) {
			$status = $this->getStatus($array['url']);
			$this->mirrors[$key]['online'] = $status['online'];
			$this->mirrors[$key]['bytes'] = $status['bytes'];
			$this->size = $status['bytes'];
			$this->file_id = $this->getFileID();
			$result = $this->mysqli->query("SELECT `downloaded`, `mirror_id` FROM `" . $this->prefix . "downloads_mirrors` WHERE `name` = '" . $array['name'] . "' AND `file_id` = '" . $this->file_id . "' LIMIT 1");
			if ($result->num_rows == 0) {
				$query = "INSERT INTO `" . $this->prefix . "downloads_mirrors` (`date`, `name`, `file_id`, `link`, `limit`) VALUES (NOW(), '" . $array['name'] . "', '" . $this->file_id . "', '" . $array['url'] . "', '" . $array['limit'] . "')"; 
				if ($this->mysqli->query($query) !== true) {
					$result->free();
					die("Failed inserting into '" . $this->prefix . "downloads_mirrors': " . mysqli_error($this->mysqli));
				}
				$result->free();
				$this->mirrors[$key]['id'] = $this->mysqli->insert_id;
				$this->mirrors[$key]['downloads'] = 0;
				$this->mirrors[$key]['totalbytes'] = 0;
			} else {
				$row = $result->fetch_array(MYSQLI_ASSOC);
				$this->mirrors[$key]['id'] = $row['mirror_id'];
				$this->mirrors[$key]['downloads'] = $row['downloaded'];
				$this->mirrors[$key]['totalbytes'] = $row['downloaded'] * $this->size;
				$result->free();
			}
		}
		$this->setMirror();
		$this->setup = true;
	}
	
	/*
	* This function checks if the URL/link is online and returns an array with the status and size of the file.
	*/
	private function getStatus($url) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.2.13) Gecko/20101203 Firefox/3.6.13');
		$data = curl_exec($ch);
		curl_close($ch);
		$online = false;
		$bytes = 0;
		if ($data === false) {
			return array('online' => false, 'bytes' => $bytes); 
		}
		if (preg_match('/^HTTP\/1\.[01] (\d\d\d)/', $data, $matches)) {
			$online = (int) $matches[1];
		}
		if (preg_match('/content-length: (\d+)/', $data, $matches)) {
			$bytes = (int) $matches[1];
		} else if (preg_match('/Content-Length: (\d+)/', $data, $matches)) {
			$bytes = (int) $matches[1];
		}
		if ($online == 200) {
			return array('online' => true, 'bytes' => $bytes); 
		} else {
			return array('online' => false, 'bytes' => $bytes); 
		}
	}	
	
	/*
	* This function is used by the class, and should not be accessed by the user.
	* It selects the mirror to download from by checking which mirror has most bandwith to use.
	* This helps spread the workforce between the mirrors.
	*/
	private function setMirror() {
		$bandwith = false;
		foreach ($this->mirrors as $key => $array) {
			if ($array['online'] && ($bandwith == false || $array['totalbytes'] == 0 || $bandwith > ($array['totalbytes'] / $array['limit']))) {
				if ($array['totalbytes'] == 0) {
					$bandwith = $array['bytes'] / $array['limit'];
				} else {
					$bandwith = $array['totalbytes'] / $array['limit'];
				}
				$this->mirror = new Mirror($array);
				$this->size = $array['bytes'];
			}			
		}
	}
	
	/*
	* This function launches the download, if there is no available it will echo a message.
	*/
	public function download() {
		if ($this->setup == false) {
			$this->setup();
		}
		if ($this->getMirror() == false) {
			echo "Could not find available mirrors, contact an Administrator!";
		} else {
			$this->logDownload();
			header("Location: " . $this->getMirror()->getURL());
		}
	}
	
	/*
	* Returns the Mirror class of the selected mirror to download from.
	*/
	public function getMirror() {
		return $this->mirror;
	}
	
	/*
	* Returns the size of the file in Bytes.
	*/
	public function getSize() {
		return $this->size;
	}
	
	/*
	* Logs the download into the database, increase the counter for both the file and the mirror.
	*/
	private function logDownload() {
		$visitor = new Visitor();
		$query = "UPDATE `" . $this->prefix . "downloads_files` SET `downloaded` = downloaded + 1 WHERE `file_id` = '" . $this->file_id . "'";
		if ($this->mysqli->query($query) !== true) {
			die("Failed updating '" . $this->prefix . "downloads_files': " . mysqli_error($this->mysqli));
		}
		$query = "UPDATE `" . $this->prefix . "downloads_mirrors` SET `downloaded` = downloaded + 1 WHERE `mirror_id` = '" . $this->getMirror()->getID() . "'";
		if ($this->mysqli->query($query) !== true) {
			die("Failed updating '" . $this->prefix . "downloads_files': " . mysqli_error($this->mysqli));
		}
		$query = "INSERT INTO `" . $this->prefix . "downloads_log` (`date`, `file_id`, `mirror_id`, `ip`, `useragent`, `referer`) VALUES (NOW(), '" . $this->file_id . "',  '" . $this->getMirror()->getID() . "', '" . $visitor->getIP() . "', '" . $visitor->getUserAgent(). "', '" . $visitor->getReferer(). "')";
		if ($this->mysqli->query($query) !== true) {
			die("Failed updating '" . $this->prefix . "downloads_files': " . mysqli_error($this->mysqli));
		}
	}
	
	/*
	* Returns the file ID of the project, this is relevant to logging.
	*/
	private function getFileID() {
		$result = $this->mysqli->query("SELECT `file_id`, `downloaded` FROM `" . $this->prefix . "downloads_files` WHERE `name` = '" . $this->name . "' AND `version` = '" . $this->version . "' LIMIT 1");
		if ($result->num_rows == 0) {
			$query = "INSERT INTO `" . $this->prefix . "downloads_files` (`date`, `name`, `version`, `bytes`) VALUES (NOW(), '" . $this->name . "', '" . $this->version . "', '" . $this->size . "')"; 
			if ($this->mysqli->query($query) !== true) {
				$result->free();
				die("Failed inserting into '" . $this->prefix . "downloads_files': " . mysqli_error($this->mysqli));
			}
			$result->free();
			return $this->mysqli->insert_id;
		} else {
			$row = $result->fetch_array(MYSQLI_ASSOC);
			$this->downloads = $row['downloaded'];
			$result->free();
			return $row['file_id'];
		}
	}
	
	/*
	* Check if all the tables are created, if they're not this function will create them.
	*/
	private function checkDatabase() {
		$result = $this->mysqli->query("SHOW TABLES LIKE '" . $this->prefix . "downloads_files'");
		if ($result->num_rows == 0) {
			$query = "CREATE TABLE IF NOT EXISTS `" . $this->prefix . "downloads_files` (
								  `file_id` int(4) NOT NULL AUTO_INCREMENT,
								  `date` datetime NOT NULL,
								  `name` varchar(60) NOT NULL,
								  `version` varchar(20) NOT NULL,
								  `downloaded` int(7) NOT NULL DEFAULT '0',
								  `bytes` int(15) NOT NULL DEFAULT '0'
								  PRIMARY KEY (`file_id`)
								) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;";
			if ($this->mysqli->query($query) !== true) {
				$result->free();
				die("Failed creating table for '" . $this->prefix . "downloads_files': " . mysqli_error($this->mysqli));
			}
		}
		$result->free();
		$result = $this->mysqli->query("SHOW TABLES LIKE '" . $this->prefix . "downloads_log'");
		if ($result->num_rows == 0) {
			$query = "CREATE TABLE IF NOT EXISTS `" . $this->prefix . "downloads_log` (
								  `log_id` int(8) NOT NULL AUTO_INCREMENT,
								  `date` datetime NOT NULL,
								  `file_id` int(4) NOT NULL,
								  `mirror_id` int(5) NOT NULL,
								  `ip` varchar(39) NOT NULL,
								  `useragent` text NOT NULL,
								  `referer` varchar(255) NOT NULL,
								  PRIMARY KEY (`log_id`)
								) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;";
			if ($this->mysqli->query($query) !== true) {
				$result->free();
				die("Failed creating table for '" . $this->prefix . "downloads_log': " . mysqli_error($this->mysqli));
			}
		}
		$result->free();
		$result = $this->mysqli->query("SHOW TABLES LIKE '" . $this->prefix . "downloads_mirrors'");
		if ($result->num_rows == 0) {
			$query = "CREATE TABLE IF NOT EXISTS `" . $this->prefix . "downloads_mirrors` (
								  `mirror_id` int(8) NOT NULL AUTO_INCREMENT,
								  `name` varchar(50) NOT NULL,
								  `date` datetime NOT NULL,
								  `file_id` int(4) NOT NULL,
								  `limit` int(10) NOT NULL,
								  `link` varchar(255) NOT NULL,
								  `downloaded` int(7) NOT NULL DEFAULT '0',
								  PRIMARY KEY (`mirror_id`)
								) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=0;";
			if ($this->mysqli->query($query) !== true) {
				$result->free();
				die("Failed creating table for '" . $this->prefix . "downloads_mirrors': " . mysqli_error($this->mysqli));
			}
		}
		$result->free();
	}
}

/*
* This class contains any related information and functions about the mirror.
*/
class Mirror {
	private $data;
	
	public function __construct($data) {
		$this->data = $data;
	}
	
	/*
	* Returns the ID of the mirror.
	*/
	public function getID() {
		return $this->data['id'];
	}
	
	/*
	* Returns the link/URL of the mirror.
	*/
	public function getURL() {
		return $this->data['url'];
	}
	
	/*
	* Returns the GB bandwith/traffic limit of the mirror .
	*/
	public function getLimit() {
		return $this->data['limit'];
	}
	
	/*
	* Returns the name of the mirror.
	*/
	public function getName() {
		return $this->data['name'];
	}
	
	/*
	* Returns the total amount of bytes that the mirror has provided.
	*/
	public function getTotalBytes() {
		return $this->data['totalbytes'];
	}
}

/*
* This class contains any related information and function about the client/visitor.
*/
class Visitor {
	private $ip, $useragent, $referer;
	
	public function __construct() {
		if (isset($_SERVER['HTTP_CLIENT_IP'])) {
			$this->ip = $_SERVER['HTTP_CLIENT_IP'];
		} else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$this->ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else if(isset($_SERVER['REMOTE_ADDR'])) {
			$this->ip = $_SERVER['REMOTE_ADDR'];
		}
		if (isset($_SERVER['HTTP_USER_AGENT'])) { 
			$this->useragent = $_SERVER['HTTP_USER_AGENT']; 
		}
		if (isset($_SERVER['HTTP_REFERER'])) { 
			$this->referer = $_SERVER['HTTP_REFERER']; 
		}
	}
	
	/*
	* Returns the IP of the visitor.
	*/
	public function getIP() {
		return $this->ip;
	}

	/*
	* Returns the User Agent of the visitor.
	*/
	public function getUserAgent() {
		return $this->useragent;
	}

	/*
	* Returns the referer of the visitor.
	*/
	public function getReferer() {
		return $this->referer;
	}
}
?>