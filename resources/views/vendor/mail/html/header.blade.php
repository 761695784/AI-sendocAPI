@props(['url'])
<tr>
<td class="header">
<table class="header-band" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#31287C">
<tr>
<td class="header-cell" align="center">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ rtrim(config('doctrack.frontend_url'), '/') }}/assets/brand/logo-doctrack-fond-sombre.png" class="logo" width="180" height="54" alt="DocTrack">
</a>
</td>
</tr>
</table>
</td>
</tr>
