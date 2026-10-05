{{-- Live checklist for a new password field (resources/js/forms.js). Same rules as the server. --}}
@props(['for' => 'password'])

<ul id="{{ $for }}-rules" class="password-rules" data-password-rules="{{ $for }}">
    <li data-rule="length">At least 8 characters</li>
    <li data-rule="case">Upper and lower case letters</li>
    <li data-rule="number">A number</li>
    <li data-rule="symbol">A symbol, like ! or #</li>
</ul>
