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
include_once "MirrorDownloader.php";

$downloader = new MirrorDownloader("My Project", "1.0.0");
$downloader->addMirror("contex.me", "http://contex.me/myproject.zip", 8000);
$downloader->addMirror("dropbox.com/Contex", "http://dropbox.com/u/00000000/myproject.zip", 20);

echo "File size: " . $downloader->getSize() . "<br>";
echo "Mirror ID: " . $downloader->getMirror()->getID() . "<br>";
echo "Mirror Name: " . $downloader->getMirror()->getName() . "<br>";
echo "Mirror URL/link: " . $downloader->getMirror()->getURL() . "<br>";
echo "Mirror Limit: " . $downloader->getMirror()->getLimit() . " GB<br>";

echo "Mirror Bytes Downloaded: " . $downloader->getMirror()->getTotalBytes();
$downloader->download();
?>