{{-- Honeypot (SF-48): off-screen, skipped by keyboard and screen readers; bots fill it in. --}}
<div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
    <label>Website <input type="text" wire:model="website" tabindex="-1" autocomplete="off"></label>
</div>
{{-- MB-23, TM-14: «pwa» when the page runs in the installed app (display-mode: standalone); the server accepts only the values it allows. --}}
<input type="hidden" name="channel" wire:model="channel" data-channel-field
       x-data x-init="if (window.shopPwa && window.shopPwa.standalone()) { $wire.channel = 'pwa' }">
