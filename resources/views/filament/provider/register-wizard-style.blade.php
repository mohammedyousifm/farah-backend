{{-- Sign-up wizard buttons. Filament keeps an empty "cancel" slot on the first step, which pushed "Next" to the
     far end (the left in Arabic). Without it: step 1 shows "Next" at the start (right in Arabic); later steps
     show "Back" at the start and "Next" / "Create account" at the end. --}}
<style>
    .farah-signup-wizard .fi-sc-wizard-footer > div:nth-child(2) { display: none !important; }
</style>
