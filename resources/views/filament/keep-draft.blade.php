{{-- KeepsDraft: send what was typed when the person leaves a field, so a refresh does not lose it. --}}
<div x-data x-init="$el.closest('[wire\\:id]')?.addEventListener('focusout', (e) => {
    if (e.target.matches('input:not([type=password]):not([type=file]), textarea')) $wire.$commit()
})" hidden></div>
