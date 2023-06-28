{* BLUF 4.5 SLUT link inbound page *}
{extends file='inner.tpl'}
{block name='title'}Social link request{/block}
{block name='main'}
{if !isset($result)}
<div class="row">
	<div class="twelve columns">
		<h1>Do you want to add a link to your account?</h1>
		<p>The user <b>{$displayname}</b> on <b>{$service}</b> has requested a link to your BLUF account.</p>
		<p>If you accept this link, then it will appear on your BLUF profile, and your BLUF number will be displayed on your {$service} profile.</p>
		<p>Additionally, if you change your BLUF number, or delete your BLUF account, then we will notify {$service}, so they can update your profile there.</p>
		<p>To accept the link, click the button below. If you do not want to accept the link, just close this window.</p>
	</div>
</div>
<div class="row" style="margin-top:5rem">
	<div class="four columns offset-by-four">
		<form method="/slutlink.php">
			<input type="hidden" name="r" value="{$r}">
			<input type="hidden" name="s" value="{$s}">
			<input type="submit" value="Accept social link" class="button button-primary u-full-width">
		</form>
	</div>
</div>
{else}
<div class="row">
	<div class="twelve columns">
		{if ( $result) == 'ok'}
		<h1>Links updated</h1>
		<p>Your links have been updated with information about your account on {$service}.</p>
		{else}
		<h1>Link update failed</h1>
		<p>Sorry. It was not possible to link your account to {$service}. Please try again later.</p>
		{/if}
		<p>You can now close this window</p>
	</div>
</div>
{/if}
{/block}