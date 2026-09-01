@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- The mark carries the name as its alt text, so a client that blocks images
     still shows who the mail is from rather than an empty box. --}}
<img src="{{ url('/email-logo.png') }}" class="logo" alt="{{ trim($slot) }}">
<span class="logo-name">{!! $slot !!}</span>
</a>
</td>
</tr>
