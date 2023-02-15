#!/usr/bin/php

<?php
$file = "ldap_card.html";
$srv = "dc.ksc.loc";
$uname = "mail@ksc.loc";
$upasswd = "d0M4iLer";
$dn = "dc=ksc,dc=loc";
//$search = "(&(jpegPhoto=*))";
$search = "(&(mail=*))";
$ds=ldap_connect($srv);
if (!$ds) die("error connect to LDAP server $srv");
$r=ldap_bind($ds, $uname, $upasswd);
if (!$r) die("error bind!");
$sr=ldap_search($ds, $dn, iconv("utf-8", "cp1251" ,$search), array('name','title','company','mail','jpegphoto'));
//$sr=ldap_search($ds, $dn, iconv("utf-8", "cp1251" ,$search), array('name','title','company','mail'));
if (!$sr) die("search error!");

$w=fopen($file,'wa+'); // 6

fwrite($w,"Результат поиска:<br />");  // 7
//echo "Результат поиска:<br />";
fwrite($w,"Получено записей - " . ldap_count_entries($ds, $sr) . "<br />");  // 7
//echo "Получено записей - " . ldap_count_entries($ds, $sr) . "<br />";
fwrite($w,"<hr />");  // 7
//echo "<hr />";
$info = ldap_get_entries($ds, $sr);
for ($i=0; $i<$info["count"]; $i++) {
fwrite($w,"<b>ФИО:</b> " . $info[$i]["name"][0] . "<br />");  // 7
//echo "<b>ФИО:</b> " . iconv("cp1251", "utf-8", $info[$i]["name"][0]) . "<br />";
fwrite($w,"<b>должность, телефон:</b> " . $info[$i]["title"][0] . "<br />");  // 7
//echo "<b>должность, телефон:</b> " . iconv("cp1251", "utf-8", $info[$i]["title"][0]) . "<br />";
fwrite($w,"<b>фирма:</b> " . $info[$i]["company"][0] . "<br />");  // 7
//echo "<b>фирма:</b> " . iconv("cp1251", "utf-8", $info[$i]["company"][0]) . "<br />";
fwrite($w,"<b>email:</b> <a href=\"mailto:" . $info[$i]["mail"][0] . "\">" . $info[$i]["mail"][0] . "</a><br />");  // 7
//echo "<b>email:</b> <a href=\"mailto:" . $info[$i]["mail"][0] . "\">" . $info[$i]["mail"][0] . "</a><br />";
fwrite($w,"<b>фото: </b>");  // 7
$photo = base64_encode($info[$i]['jpegphoto'][0]);
fwrite($w,"<img src=\"data:image/jpeg;base64,".$photo."\" />");
//echo "<b>фото: </b>";
//if ($photo != "")   // 7//echo "<img src=\"data:image/jpeg;base64,".$photo."\" />";
//else fwrite($w,"фотографии нет");  // 7//echo "фотографии нет";
fwrite($w,"<hr />");  // 7
//echo "<hr />";
}
fclose($w);  // 8
ldap_close($ds);
?>