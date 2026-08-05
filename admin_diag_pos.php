<?php
require_once __DIR__ . "/includes/session_bootstrap.php";
startRoleSession("admin");
$_SESSION["user_id"]="ADMIN-003"; $_SESSION["fullname"]="Monaliza V. Mancilla";
$_SESSION["role"]="admin"; $_SESSION["department"]="PMO";
?><!DOCTYPE html><html><body><pre id="out">x</pre>
<iframe id="f" src="admin_users.php?page=5&search=an&type=student" style="width:1440px;height:900px;border:0"></iframe>
<script>
document.getElementById("f").addEventListener("load",function(){
  var d=this.contentDocument, p=d.querySelector(".pager");
  document.getElementById("out").textContent = p ? JSON.stringify({top:Math.round(p.getBoundingClientRect().top+this.contentWindow.scrollY), h:Math.round(p.getBoundingClientRect().height), docH:d.body.scrollHeight}) : "NO PAGER";
});
</script></body></html>
