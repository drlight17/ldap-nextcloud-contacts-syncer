#!/usr/bin/php

<?php
/**
 * ldap with Nextcloud contacts app syncer
 *
 * @link https://github.com/drlight17/ldap-nextcloud-contacts-syncer
 * @author Samoilov Yuri
 * @version 0.1
*/

include("db-connect.inc"); // access db
include("vCard.php"); // for vcard parsing
error_reporting(0);

//***********set config variables************
// mysql settings
$db_dst=$mysql_connect['tbl_dst'];      //cards
$db_dst_2=$mysql_connect['tbl_dst_2']; //cards_properties
$db_dst_3=$mysql_connect['tbl_dst_3']; //addressbookchanges
$db_dst_4=$mysql_connect['tbl_dst_4']; //addressbooks
$db_cloud=$mysql_connect['db_cloud'];
$host_cloud=$mysql_connect['host_cloud']; //host with cloud db
$user=$mysql_connect['user'];
$passwd=$mysql_connect['pass'];
$addressbookid=23; // change book id from NC database. !!!in order to start sync there must be at least 1 contact in this addressbook!!!
$temp_file='temp.vcf';
$log="/var/log/addr_sync.log";
// ldap settings
$srv = $ldap_connect['ldap_srv'];					// ldap server address
$uname = $ldap_connect['ldap_user'];				// ldap bind user
$upasswd = $ldap_connect['ldap_password'];			// ldap bind user password
$dn = $ldap_connect['ldap_base_dn'];				// ldap search base dn
$dn_aliases = $ldap_connect['ldap_dn_aliases'];                // ldap search aliases base dn
$ldap_domain = $ldap_connect['ldap_domain'];		// ldap domain for AD userPrincipalName attr
$ldap_user_search = $ldap_connect['ldap_user_search'];        // ldap user attr search
$ldap_aliases_search = $ldap_connect['ldap_aliases_search'];        // ldap aliase attr search
//******************************************

function get_ldap_attributes ($ldap_attributes) {
//    $search = "(&(jpegPhoto=*))";
//    $search = "(&(mail=*))";
//	$search = "(&(mail=*)(|(&(objectClass=user)(!(objectClass=computer))))(!(description=service))(carLicense=YES))";
//	$search = "(&(mail=*)(|(&(objectClass=user)(!(objectClass=computer))(!(userAccountControl:1.2.840.113556.1.4.803:=2))))(!(description=service))(carLicense=YES))";
//	$search = "(&(mail=test@ksc.ru)(|(&(objectClass=user)(!(objectClass=computer))(!(userAccountControl:1.2.840.113556.1.4.803:=2))))(!(description=service))(carLicense=YES))";
    global $srv, $uname, $upasswd, $dn, $ldap_user_search;
    $search = $ldap_user_search;
    $ds=ldap_connect($srv);
    if (!$ds) die("error connect to LDAP server $srv");
    $r=ldap_bind($ds, $uname, $upasswd);
    if (!$r) die("error bind!");
    $sr=ldap_search($ds, $dn, iconv("utf-8", "cp1251" ,$search), $ldap_attributes);
    if (!$sr) die("search error!");
    $info = ldap_get_entries($ds, $sr);
    return $info;
}

function get_aliases ($attributes_aliases) {
	//$search = "(&(objectClass=group)(!(info=service)))";
	//$search = "(&(objectClass=group)(cn=adm_zakupki)(!(info=service)))";
    global $srv, $uname, $upasswd, $dn_aliases, $ldap_aliases_search;
    $search = $ldap_aliases_search;
    $dn = $dn_aliases;
    $ds=ldap_connect($srv);
    if (!$ds) die("error connect to LDAP server $srv");
	ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    $r=ldap_bind($ds, $uname, $upasswd);
    if (!$r) die("error bind!");
    $sr=ldap_search($ds, $dn, iconv("utf-8", "cp1251", $search), $attributes_aliases);
	$error = ldap_error($ds);
	if (!($sr)) die("search error!");
    $info = ldap_get_entries($ds, $sr);
    return $info;
}

function HumanToUnixTime($Time) {
  return strtotime($Time."+3 hours"); // convert to current timezone
}

function ldapTimeToUnixTime($ldapTime) {
  $secsAfterADEpoch = $ldapTime / 10000000;
  $ADToUnixConverter = ((1970 - 1601) * 365 - 3 + round((1970 - 1601) / 4)) * 86400;
  return intval($secsAfterADEpoch - $ADToUnixConverter);
}

function create_vcard($last_name,$first_name,$middle_name,$display_name,$categories,$organization,$job_title,$work_phone,$primary_email,$uid,$user_photo,$rev){
return
'BEGIN:VCARD
VERSION:3.0
N:'.$last_name.';'.$first_name.';'.$middle_name.';;
FN:'.$display_name.'
CATEGORIES:'.$categories.'
ORG:'.$organization.';
TITLE:'.$job_title.'
TEL;TYPE="VOICE,WORK";VALUE=TEXT:'.$work_phone.'
EMAIL;TYPE="HOME,INTERNET,pref":'.$primary_email.'
UID:'.$uid.'
REV;VALUE=DATE-AND-OR-TIME:'.$rev.'
PHOTO;'.$user_photo.'
END:VCARD';
}

function OutputvCard_mail(vCard $vCard)
    {
        if ($vCard -> EMAIL)
        {
            foreach ($vCard -> EMAIL as $Email)
            {
                if (is_scalar($Email))
                {
                    return $Email;
                }
                else
                {
                    return $Email['Value'];
                }
            }
        }
    }

function OutputvCard_photo (vCard $vCard)
    {
        if ($vCard -> PHOTO)
        {
            foreach ($vCard -> PHOTO as $Photo)
            {
                if ($Photo['Encoding'] == 'b')
                {
                   /* if ($Photo['Value']=='VCARD') {
                        //return $Photo['Type'][0].';base64,'.'nothing';
                        return false;
                    }*/
                    return $Photo['Type'][0].';base64,'.$Photo['Value'];
                }
                else
                {
                    return $Photo['Value'];
                }
            }
        } else {

            return false;
	}
    }

function add_or_update ($ldap_array, $addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $db_dst_4, $log) {
    $w=fopen($log,'a');
    //while ($select_array = mysqli_fetch_array($select_query_mail, MYSQLI_ASSOC)) {
	for ($i=0; $i<$ldap_array[count]; $i++ ) {
		$user_photo="";
        //echo "flag!";
        // uid generator
        $uid=bin2hex(random_bytes(4))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(6));
        $uri=strtoupper($uid).".vcf";
        // timestamp generator

        //$lastmodified=time(); //current timestamp
        //$last_update_ldap=strtotime($select_array["last_update"]); // ldap time convert to timestamp
		$last_update_ldap = HumanToUnixTime(explode('.', $ldap_array[$i]['whenchanged'][0])[0]); // ldap time convert to timestamp
        $lastmodified=$last_update_ldap; // sync cloud lastmodified with ldap whenchanged
		$lastlogon=ldapTimeToUnixTime($ldap_array[$i]["lastlogontimestamp"][0]);
        //echo $last_update_ldap;
        $rev=gmdate('Ymd')."T".gmdate('His')."Z";
        //$last_name=$select_array["last_name"];
	$last_name = $ldap_array[$i]["sn"][0];
        $first_name=explode(' ', $ldap_array[$i]["givenname"][0])[0];
        $middle_name=explode(' ', $ldap_array[$i]["givenname"][0])[1];
	if (!($ldap_array[$i]["displayname"][0]=="")) {
    	    $display_name=$ldap_array[$i]["displayname"][0];
	} else {
	    $display_name=$last_name." ".$first_name." ".$middle_name;
	}
        $categories=$ldap_array[$i]["o"][0];
        $organization=$ldap_array[$i]["o"][0];
        $job_title=$ldap_array[$i]["description"][0];
        $work_phone=$ldap_array[$i]["telephonenumber"][0];
	// samoilov 19.05.2022 fix of _ sql escape
        $primary_email=$ldap_array[$i]["mail"][0];
        $primary_email_escaped=str_replace(['_', '%'], ['\_', '\%'], $ldap_array[$i]["mail"][0]);
		//echo "Checking ".$primary_email."\n";
		//foreach($ldap_array as $ldap_array_element){
		//if ($primary_email==$ldap_array["mail"][0]) {
			if (isset($ldap_array[$i]["jpegphoto"][0])) {
				$user_photo="ENCODING=b;TYPE=png:".base64_encode($ldap_array[$i]["jpegphoto"][0]);
			} else {
			$user_photo="ENCODING=b;TYPE=png:nothing";
			};
		//};
		//};
    if ($user_photo=="") {
//          $user_photo=$select_array["user_photo"];
        $user_photo="ENCODING=b;TYPE=png:nothing";
    };
        $vcard=create_vcard($last_name,$first_name,$middle_name,$display_name,$categories,$organization,$job_title,$work_phone,$primary_email,$uid,$user_photo,$rev);
        $vcard_array= array(
            "N" => $last_name.";".$first_name.";".$middle_name.";;",
            "FN" => $display_name,
            "CATEGORIES"   => $categories,
            "ORG"  => $organization,
            "TITLE"  => $job_title,
            "TEL"  => $work_phone,
            "EMAIL"  => $primary_email,
            "PHOTO"  => $user_photo,
            "UID"  => $uid,
        );
        // etag generator
        $etag = md5($vcard);
        $size = strlen($vcard);
        /*if (check_existence($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email, $last_update_exim)) {*/
        $check_result = check_existence($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email);
        //print_r($check_result );
	//echo ($check_result[1]['carddata']);
	
        if ($check_result[0]===true) {
            // add record
            //echo "add of user!";
            $link_db=connect_to_db ($host_cloud, $user, $passwd);
            $insert="INSERT INTO ".$db_cloud.".".$db_dst." (`addressbookid`,`carddata`,`uri`, `lastmodified`, `etag`, `size`, `uid`) VALUES"."('".$addressbookid."','".$vcard."','".$uri."','".$lastmodified."','".$etag."','".$size."','".$uid."')";
            $insert_query=mysqli_query($link_db, $insert) or die("Query failed");
            $cardid = mysqli_insert_id($link_db);
            foreach ($vcard_array as $name => $value) {
                $insert_2="INSERT INTO ".$db_cloud.".".$db_dst_2." (`id`,`addressbookid`,`cardid`,`name`, `value`, `preferred`) VALUES"."(NULL, '".$addressbookid."','".$cardid."','".$name."','".$value."',0)";
                $insert_query_2=mysqli_query($link_db, $insert_2) or die("Query failed");
            }
            $select_2="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";
            $select_query_2=mysqli_query($link_db, $select_2) or die("Query failed");
            while ($select_array_2 = mysqli_fetch_array($select_query_2, MYSQLI_ASSOC)) {
                $synctoken=$select_array_2["synctoken"];
            }
            // operations: 1 - add, 2 - modify, 3 - delete
            $insert_3="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 1)";
            $insert_query_3=mysqli_query($link_db, $insert_3) or die("Query failed");
            $synctoken+=1;
            $update="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
            $update_query=mysqli_query($link_db, $update) or die("Query failed");
            fwrite($w,(date(DATE_RFC822)));
            fwrite($w," добавлен новый пользователь ".$display_name." с адресом ".$primary_email. "\n");
            mysqli_close($link_db);
        } else {
            // update record
            // samoilov 23.10.2020 check last_update
	    //echo $display_name."\n";
	    //echo $check_result[1]."\n";
	    //echo $last_update_ldap."\n";
	    //
            if ($check_result[1]['lastmodified'] < $last_update_ldap) {
					//echo "update of user!";
					//echo $display_name."\n";
					//echo $primary_email."\n";
					//echo "Nextcloud lastmodified timestamp: ".$check_result[1]."\n";
					//echo "LDAP whenchanged timestamp: ".$last_update_ldap."\n";
					//echo "lastmodified var timestamp: ".$lastmodified."\n";
					//echo "lastlogon timestamp: ".$lastlogon."\n";

				// samoilov check if some values in NC db were changed
				$carddata_changed = false;
				if (strpos($check_result[1]['carddata'], $display_name)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $primary_email)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $categories)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $organization)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $job_title)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $work_phone)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $user_photo)==false) {
				    $carddata_changed = true;
				}
				if ($carddata_changed) {
					//echo "Something is changed!";
					$link_db=connect_to_db ($host_cloud, $user, $passwd);
					// sql query to find uid
					//$select_4="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".$primary_email."%' and addressbookid='".$addressbookid."'";
					// samoilov 01.11.2022 escape _ fix addition
					//$select_4="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%EMAIL;%' and carddata like '%:".$primary_email."%' and addressbookid='".$addressbookid."'";
					$select_4="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%EMAIL;%' and carddata like '%:".$primary_email_escaped."%' and addressbookid='".$addressbookid."'";
				$select_query_4=mysqli_query($link_db, $select_4) or die("Query failed");
					while ($select_array_4 = mysqli_fetch_array($select_query_4, MYSQLI_ASSOC)) {
						$cardid=$select_array_4["id"];
						$uid=$select_array_4["uid"];
						$uri=$select_array_4["uri"];
					};
					$update="UPDATE ".$db_cloud.".".$db_dst." SET `carddata`='".$vcard."', `lastmodified`='".$lastmodified."', `etag`='".$etag."', `size`='".$size."' WHERE id='".$cardid."'";
					$update_query=mysqli_query($link_db, $update) or die("Query failed");
					//$cardid = mysqli_insert_id($link_db);
					foreach ($vcard_array as $name => $value) {
						//$update_3="INSERT INTO ".$db_cloud.".".$db_dst_2." (`id`,`addressbookid`,`cardid`,`name`, `value`, `preferred`) VALUES"."(NULL, '".$addressbookid."','".$cardid."','".$name."','".$value."',0)";
						$update_3="UPDATE ".$db_cloud.".".$db_dst_2." SET `value`='".$value."', `preferred`=0 WHERE `name`='".$name."' AND `addressbookid`='".$addressbookid."' AND `cardid`='".$cardid."'";
						//echo $update_3;
						$update_query_3=mysqli_query($link_db, $update_3) or die("Query failed");
					}

					$select_3="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";
				$select_query_3=mysqli_query($link_db, $select_3) or die("Query failed");
				while ($select_array_3 = mysqli_fetch_array($select_query_3, MYSQLI_ASSOC)) {
							$synctoken=$select_array_3["synctoken"];
				}
					// operations: 1 - add, 2 - modify, 3 - delete
					$insert="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 2)";
				$insert_query=mysqli_query($link_db, $insert) or die("Query failed");
				$synctoken+=1;
					$update_2="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
				$update_query_2=mysqli_query($link_db, $update_2) or die("Query failed");
					fwrite($w,(date(DATE_RFC822)));
					fwrite($w," обновлены данные пользователя ".$display_name." с адресом ".$primary_email. "\n");
					mysqli_close($link_db);
				} else {
				    //echo "Nothing is changed!";
				}
            }
        }

    }
    fclose($w);
    return 0;
}

function add_or_update_alias ($ldap_array, $addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $db_dst_4, $log) {
    $w=fopen($log,'a');
    //while ($select_array = mysqli_fetch_array($select_query_mail, MYSQLI_ASSOC)) {
	for ($i=0; $i<$ldap_array[count]; $i++ ) {
        //echo "flag!";
        // uid generator
        $uid=bin2hex(random_bytes(4))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(6));
        $uri=strtoupper($uid).".vcf";
        // timestamp generator

        //$lastmodified=time(); //current timestamp
        //$last_update_ldap=strtotime($select_array["last_update"]); // ldap time convert to timestamp
		$last_update_ldap = HumanToUnixTime(explode('.', $ldap_array[$i]['whenchanged'][0])[0]); // ldap time convert to timestamp
        $lastmodified=$last_update_ldap; // sync cloud lastmodified with ldap whenchanged
        //echo $last_update_ldap;
        $rev=gmdate('Ymd')."T".gmdate('His')."Z";
        //$last_name=$select_array["last_name"];
		if ($ldap_array[$i]["description"][0]=="") {$ldap_array[$i]["description"][0] = $ldap_array[$i]["cn"][0]."@ksc.ru";}
		$last_name = "";
        $first_name=$ldap_array[$i]["description"][0];
        $middle_name="";
        $display_name=$first_name;
        $categories="Рассылки";
        $organization="";
        $job_title=$ldap_array[$i]["description"][0];
        $work_phone="";
	// samoilov 19.05.2022 fix of _ sql escape        
        $primary_email=$ldap_array[$i]["cn"][0]."@ksc.ru";
	$primary_email_escaped=str_replace(['_', '%'], ['\_', '\%'], $ldap_array[$i]["cn"][0])."@ksc.ru";
		$user_photo="ENCODING=b;TYPE=png:nothing";
		//echo "Checking ".$primary_email."\n";
		//foreach($ldap_array as $ldap_array_element){
		//if ($primary_email==$ldap_array["mail"][0]) {
		//};
		//};
        $vcard=create_vcard($last_name,$first_name,$middle_name,$display_name,$categories,$organization,$job_title,$work_phone,$primary_email,$uid,$user_photo,$rev);
        $vcard_array= array(
            "N" => $last_name.";".$first_name.";".$middle_name.";;",
            "FN" => $first_name,
            "CATEGORIES"   => $categories,
            "ORG"  => $organization,
            "TITLE"  => $job_title,
            "TEL"  => $work_phone,
            "EMAIL"  => $primary_email,
            "PHOTO"  => $user_photo,
            "UID"  => $uid,
        );
        // etag generator
        $etag = md5($vcard);
        $size = strlen($vcard);
        /*if (check_existence($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email, $last_update_exim)) {*/
        $check_result = check_existence($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email);
        if ($check_result[0]===true) {
            // add record
            //echo "add of user!";
            $link_db=connect_to_db ($host_cloud, $user, $passwd);
            $insert="INSERT INTO ".$db_cloud.".".$db_dst." (`addressbookid`,`carddata`,`uri`, `lastmodified`, `etag`, `size`, `uid`) VALUES"."('".$addressbookid."','".$vcard."','".$uri."','".$lastmodified."','".$etag."','".$size."','".$uid."')";
            $insert_query=mysqli_query($link_db, $insert) or die("Query failed");
            $cardid = mysqli_insert_id($link_db);
            foreach ($vcard_array as $name => $value) {
                $insert_2="INSERT INTO ".$db_cloud.".".$db_dst_2." (`id`,`addressbookid`,`cardid`,`name`, `value`, `preferred`) VALUES"."(NULL, '".$addressbookid."','".$cardid."','".$name."','".$value."',0)";
                $insert_query_2=mysqli_query($link_db, $insert_2) or die("Query failed");
            }
            $select_2="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";
            $select_query_2=mysqli_query($link_db, $select_2) or die("Query failed");
            while ($select_array_2 = mysqli_fetch_array($select_query_2, MYSQLI_ASSOC)) {
                $synctoken=$select_array_2["synctoken"];
            }
            // operations: 1 - add, 2 - modify, 3 - delete
            $insert_3="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 1)";
            $insert_query_3=mysqli_query($link_db, $insert_3) or die("Query failed");
            $synctoken+=1;
            $update="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
            $update_query=mysqli_query($link_db, $update) or die("Query failed");
            fwrite($w,(date(DATE_RFC822)));
            fwrite($w," добавлен новый пользователь ".$display_name." с адресом ".$primary_email. "\n");
            mysqli_close($link_db);
        } else {
            // update record
            // samoilov 23.10.2020 check last_update
	    //echo $display_name."\n";
	    //echo $check_resul[1]."\n";
	    //echo $last_update_ldap."\n";
            if ($check_result[1]['lastmodified'] < $last_update_ldap) {
					//echo "update of user!";
					//echo $display_name."\n";
					//echo $primary_email."\n";
					//echo "Nextcloud lastmodified timestamp: ".$check_result[1]."\n";
					//echo "LDAP whenchanged timestamp: ".$last_update_ldap."\n";
					//echo "lastmodified var timestamp: ".$lastmodified."\n";
				// samoilov check if some values in NC db were changed
				$carddata_changed = false;
				if (strpos($check_result[1]['carddata'], $first_name)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $primary_email)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $categories)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $organization)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $job_title)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $work_phone)==false) {
				    $carddata_changed = true;
				}
				if (strpos($check_result[1]['carddata'], $user_photo)==false) {
				    $carddata_changed = true;
				}
				if ($carddata_changed) {
					$link_db=connect_to_db ($host_cloud, $user, $passwd);
					// sql query to find uid
					//$select_4="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".$primary_email."%' and addressbookid='".$addressbookid."'";
					// samoilov 01.11.22 escaped _ fix addition
					//$select_4="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%EMAIL;%' and carddata like '%:".$primary_email."%' and addressbookid='".$addressbookid."'";
					$select_4="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%EMAIL;%' and carddata like '%:".$primary_email_escaped."%' and addressbookid='".$addressbookid."'";
				$select_query_4=mysqli_query($link_db, $select_4) or die("Query failed");
					while ($select_array_4 = mysqli_fetch_array($select_query_4, MYSQLI_ASSOC)) {
						$cardid=$select_array_4["id"];
						$uid=$select_array_4["uid"];
						$uri=$select_array_4["uri"];
					};
					$update="UPDATE ".$db_cloud.".".$db_dst." SET `carddata`='".$vcard."', `lastmodified`='".$lastmodified."', `etag`='".$etag."', `size`='".$size."' WHERE id='".$cardid."'";
					$update_query=mysqli_query($link_db, $update) or die("Query failed");
					//$cardid = mysqli_insert_id($link_db);
					foreach ($vcard_array as $name => $value) {
						//$update_3="INSERT INTO ".$db_cloud.".".$db_dst_2." (`id`,`addressbookid`,`cardid`,`name`, `value`, `preferred`) VALUES"."(NULL, '".$addressbookid."','".$cardid."','".$name."','".$value."',0)";
						$update_3="UPDATE ".$db_cloud.".".$db_dst_2." SET `value`='".$value."', `preferred`=0 WHERE `name`='".$name."' AND `addressbookid`='".$addressbookid."' AND `cardid`='".$cardid."'";
						//echo $update_3;
						$update_query_3=mysqli_query($link_db, $update_3) or die("Query failed");
					}

					$select_3="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";
				$select_query_3=mysqli_query($link_db, $select_3) or die("Query failed");
				while ($select_array_3 = mysqli_fetch_array($select_query_3, MYSQLI_ASSOC)) {
							$synctoken=$select_array_3["synctoken"];
				}
					// operations: 1 - add, 2 - modify, 3 - delete
					$insert="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 2)";
				$insert_query=mysqli_query($link_db, $insert) or die("Query failed");
				$synctoken+=1;
					$update_2="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
				$update_query_2=mysqli_query($link_db, $update_2) or die("Query failed");
					fwrite($w,(date(DATE_RFC822)));
					fwrite($w," обновлены данные пользователя ".$display_name." с адресом ".$primary_email. "\n");
					mysqli_close($link_db);
				}
            }
        }

    }
    fclose($w);
    return 0;
}
//samoilov 01.11.22 escape _ fix addition
//function check_existence ($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email) {
function check_existence ($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email_escaped) {
    $link_db=connect_to_db ($host_cloud, $user, $passwd);
    // samoilov 30.12.2020 doubles creation fix in sql query
    $check="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%:".$primary_email_escaped."%' AND addressbookid='".$addressbookid."'";
    $check_query=mysqli_query($link_db, $check) or die("Query failed");
    $check_array = mysqli_fetch_array($check_query, MYSQLI_ASSOC);
    if (mysqli_affected_rows($link_db)==0) {
        return array(true, '');
    } else {
    //    return array(false, $check_array["lastmodified"]);
    return array(false, $check_array);
    }
    mysqli_close($link_db);
}

function check_and_clear_doubles ($host_cloud, $user, $passwd) {
    $link_db=connect_to_db ($host_cloud, $user, $passwd);
    $check="CALL delete_oc_cards_doubles()";
    $check_query=mysqli_query($link_db, $check);
    mysqli_close($link_db);
}

function get_all_contacts_cloud ($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst) {
    $link_db=connect_to_db ($host_cloud, $user, $passwd);
    $select="SELECT carddata from ".$db_cloud.".".$db_dst." WHERE addressbookid='".$addressbookid."'";
    $select_query=mysqli_query($link_db, $select) or die("Query failed");
    mysqli_close($link_db);
    return $select_query;
}


function delete_nonexistent ($ldap_array, $ldap_array_aliases, $addressbookid, $temp_file, $host_cloud, $user, $passwd, $db_cloud, $db_src, $db_dst, $db_dst_3, $db_dst_4, $log) {
    $email_string='';
    $i=0;
    $j=0;
	

$w=fopen($log,'a');

    //while ($select_array = mysqli_fetch_array($select_query_mail, MYSQLI_ASSOC)) {
	for ($i=0; $i<$ldap_array[count]; $i++ ) {
        $email_string.=";".$ldap_array[$i]["mail"][0].";";
    };
	for ($i=0; $i<$ldap_array_aliases[count]; $i++ ) {
        $email_string.=";".$ldap_array_aliases[$i]["cn"][0]."@ksc.ru;";
    };

    $vCard = new vCard($temp_file);
    //echo $email_string;
    if (count($vCard) == 0)
        {
            throw new Exception('vCard test: empty vCard!');
        }
    // if the file contains a single vCard, it is accessible directly.
        elseif (count($vCard) == 1)
        {
            OutputvCard_mail($vCard);
        }
    // if the file contains multiple vCards, they are accessible as elements of an array
        else
        {
            foreach ($vCard as $Index => $vCardPart)
            {   //echo OutputvCard_mail($vCardPart)."\n";
        $template=";".OutputvCard_mail($vCardPart).";";
                if(strpos($email_string,$template) !== false){
                    $i=$i+1;

                } else {
                    $j=$j+1;

                    $link_db=connect_to_db ($host_cloud, $user, $passwd);

                    $select_3="SELECT uri FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".OutputvCard_mail($vCardPart)."%' AND addressbookid='".$addressbookid."'";
            //echo $select_3;
                    $select_query_3=mysqli_query($link_db, $select_3) or die("Query failed");
                    while ($select_array_3 = mysqli_fetch_array($select_query_3, MYSQLI_ASSOC)) {
                        $uri=$select_array_3["uri"];
                    };

                    $select_2="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";
                    $select_query_2=mysqli_query($link_db, $select_2) or die("Query failed");
                    while ($select_array_2 = mysqli_fetch_array($select_query_2, MYSQLI_ASSOC)) {
                        $synctoken=$select_array_2["synctoken"];
                    };

                    $delete="DELETE FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".OutputvCard_mail($vCardPart)."%' AND addressbookid='".$addressbookid."'";
                    $delete_query=mysqli_query($link_db, $delete) or die("Query failed");

                    // operations: 1 - add, 2 - modify, 3 - delete
                    $insert="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 3)";
                    $insert_query=mysqli_query($link_db, $insert) or die("Query failed");

                    $synctoken+=1;

                    $update="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
                    $update_query=mysqli_query($link_db, $update) or die("Query failed");

                    mysqli_close($link_db);
                    fwrite($w,(date(DATE_RFC822)));
                    fwrite($w," удален пользователь с адресом ".OutputvCard_mail($vCardPart). "\n");

                }
            }
//          echo "remained: ".$i."\n";
//          echo "deleted: ".$j."\n";
        }
    fclose($w);

    return 0;
}

function connect_to_db ($host, $user, $passwd) {
    $link_db = mysqli_connect($host, $user, $passwd)
       or die("Could not connect: " . mysqli_error());
    return $link_db;
}

function create_temp_file($temp_file,$select_query_cloud) {
    $w=fopen($temp_file,'wa+');
    while ($select_array = mysqli_fetch_array($select_query_cloud, MYSQLI_ASSOC)) {
//    echo $select_array["carddata"];
    fwrite($w,$select_array["carddata"]."\n");
    }
    fclose($w);
    return 0;
}

function delete_temp_file ($temp_file) {
if (!unlink($temp_file)) {
    echo ("temp file cannot be deleted due to an error");
}
else {
    //echo ("temp file has been deleted");
}
}

//***********************************************************************************************
$w=fopen($log,'a');
fwrite($w,"Запущена синхронизация ".(date(DATE_RFC822))."\n");
fclose($w);

$select_query_cloud = get_all_contacts_cloud ($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst);

create_temp_file ($temp_file,$select_query_cloud);

$attributes=array('sAMAccountName','userPrincipalName','distinguishedName','givenName','sn','cn','displayName','description','o','department','l','roomNumber','mail','employeeType','employeeNumber','carLicense','st','jpegPhoto','telephoneNumber','whenChanged','userAccountControl','lastLogonTimestamp');
$attributes_aliases=array('cn','distinguishedName','description','whenChanged');

// 26.09.2021 TODO add visible aliases (auto and manual) in ldap array
$ldap_array = get_ldap_attributes($attributes);
//print_r($ldap_array);
$ldap_array_aliases = get_aliases($attributes_aliases);
//print_r ($ldap_array_aliases);

add_or_update_alias ($ldap_array_aliases, $addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $db_dst_4, $log);

add_or_update ($ldap_array, $addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $db_dst_4, $log);

delete_nonexistent ($ldap_array, $ldap_array_aliases, $addressbookid, $temp_file, $host_cloud, $user, $passwd, $db_cloud, $db_src, $db_dst, $db_dst_3, $db_dst_4, $log);

delete_temp_file($temp_file);

check_and_clear_doubles ($host_cloud, $user, $passwd);

//***********************************************************************************************

$w=fopen($log,'a');
fwrite($w,"Завершена синхронизация ".(date(DATE_RFC822))."\n"."\n");
fclose($w);

?>
