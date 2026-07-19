#!/bin/bash
# Pre-migration data checks against the live SQLite (read-only): invalid UTF-8 in
# text columns, and contactgroupmembers referencing missing contacts/groups
# (Postgres FKs will reject those; SQLite allowed them).
# Usage: prescan.sh <roundcube_container>
set -euo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
C="${1:?usage: prescan.sh <container>}"
PHP='
$db=new PDO("sqlite:/var/www/roundcube/temp/roundcube.db");
$bad=0;
$cols=[["identities","signature"],["identities","name"],["contacts","name"],["contacts","vcard"],["users","preferences"],["collected_addresses","name"],["collected_addresses","email"]];
foreach($cols as [$t,$c]){
  foreach($db->query("SELECT $c AS v FROM $t") as $r){
    $v=$r["v"]; if($v!==null && $v!=="" && !mb_check_encoding($v,"UTF-8")){ $bad++; }
  }
}
$dangC=$db->query("SELECT count(*) FROM contactgroupmembers m LEFT JOIN contacts c ON c.contact_id=m.contact_id WHERE c.contact_id IS NULL")->fetchColumn();
$dangG=$db->query("SELECT count(*) FROM contactgroupmembers m LEFT JOIN contactgroups g ON g.contactgroup_id=m.contactgroup_id WHERE g.contactgroup_id IS NULL")->fetchColumn();
echo "invalid_utf8=$bad dangling_contact=$dangC dangling_group=$dangG\n";
exit(($bad+$dangC+$dangG)>0 ? 3 : 0);
'
out="$("$D/../portainer-exec.sh" -u www-data "$C" php -r "$PHP" 2>&1)"
echo "$out"
echo "$out" | grep -q "invalid_utf8=0 dangling_contact=0 dangling_group=0" || { echo "PRESCAN: issues found — resolve before migrating"; exit 1; }
echo "PRESCAN: clean"
