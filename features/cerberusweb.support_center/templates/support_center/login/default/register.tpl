{if !empty($error)}
<div class="error">{$error}</div>
{/if}

<form action="{devblocks_url}c=login&a=register{/devblocks_url}" method="post" id="frmRegister">
<input type="hidden" name="a" value="doRegister">

<fieldset>
	<legend>Register</legend>

	<b>What is your primary email address?</b><br>
	<input type="text" name="email" size="64" value="{$email}"><br>
	<br>
	
	<button type="submit"><img src="{devblocks_url}c=resource&p=cerberusweb.support_center&f=images/check.gif{/devblocks_url}" align="top"> {'portal.sc.public.register.send_confirmation'|devblocks_translate}</button><br> 
</fieldset>

</form> 
