@php
    $state = $getState();
    if (is_string($state)) {
        $decoded = json_decode($state, true);
        $state = json_last_error() === JSON_ERROR_NONE ? $decoded : $state;
    }
    $text = $state === null || $state === [] ? '—' : (is_string($state) ? $state : json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
@endphp

<pre class="farah-json rounded-lg bg-gray-50 p-3 dark:bg-white/5">{{ $text }}</pre>
