<div class="farah-auth-footer">
    <a href="{{ route('admin.locale', app()->getLocale() === 'ar' ? 'en' : 'ar') }}">{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</a>
    @if ($showHome ?? true)
        <span aria-hidden="true">·</span>
        <a href="{{ url('/') }}">{{ __('provider.auth.back_home') }}</a>
    @endif
</div>
<style>
    .farah-auth-footer { display: flex; justify-content: center; gap: .6rem; margin-top: 1.25rem; font-size: .85rem; opacity: .75; }
    .farah-auth-footer a:hover { text-decoration: underline; }
</style>
