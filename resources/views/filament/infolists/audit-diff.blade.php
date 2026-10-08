@php
    $record = $getRecord();
    $old = $record->old_values ?? [];
    $new = $record->new_values ?? [];
    $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
    $show = function ($value) {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return (string) $value;
    };
@endphp

@if (empty($keys))
    <p class="text-sm text-gray-500">{{ __('admin.audit.no_changes') }}</p>
@else
    <table class="farah-diff w-full text-sm">
        <thead>
            <tr class="font-semibold">
                <td>{{ __('admin.fields.field') }}</td>
                <td>{{ __('admin.audit.old') }}</td>
                <td>{{ __('admin.audit.new') }}</td>
            </tr>
        </thead>
        <tbody>
            @foreach ($keys as $key)
                @php
                    $hasOld = array_key_exists($key, $old);
                    $hasNew = array_key_exists($key, $new);
                    $changed = $hasOld && $hasNew;
                @endphp
                <tr>
                    <td>
                        @if (\Illuminate\Support\Facades\Lang::has("admin.fields.{$key}"))
                            <div class="font-medium">{{ __("admin.fields.{$key}") }}</div>
                        @endif
                        <div class="font-mono text-xs opacity-60" dir="ltr">{{ $key }}</div>
                    </td>
                    <td style="{{ $changed ? 'color: rgb(225 29 72); text-decoration: line-through;' : '' }}">
                        <span class="farah-json">{{ $hasOld ? $show($old[$key]) : '' }}</span>
                    </td>
                    <td style="{{ $changed ? 'color: rgb(5 150 105);' : '' }}">
                        <span class="farah-json">{{ $hasNew ? $show($new[$key]) : '' }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
