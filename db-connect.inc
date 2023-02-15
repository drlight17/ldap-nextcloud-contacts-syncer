<?php 
  $mysql_connect = array
  (
    "host_cloud"		=> "nextcloud.domain.com",
	"user"			=> "nc_username",
	"pass"			=> "nc_password",
	"db_cloud"		=> "nextcloud",
	"tbl_dst" 		=> "oc_cards",
	"tbl_dst_2" 		=> "oc_cards_properties",
	"tbl_dst_3" 		=> "oc_addressbookchanges",
	"tbl_dst_4" 		=> "oc_addressbooks"
  );
   $ldap_connect = array
  (
	"ldap_srv" 		=> "dc.domain.com",
	"ldap_user"		=> "dn_user@domain.com",
	"ldap_password"		=> "ldap_password",
	"ldap_base_dn" 		=> "dc=domain,dc=com",
	"ldap_domain" 		=> "domain.com",
	"ldap_user_search" 		=> "(&(mail=*))",
	"ldap_aliases_search" 		=> "(&(mail=*)(objectClass=group))",
	"ldap_dn_aliases" 		=> "ou=aliases,dc=domain,dc=com"
  );
?>