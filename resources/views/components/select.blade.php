@props(['label', 'name', 'options', 'selected', 'hint'])

<div {{ $attributes }}>
    @isset($label)
        <label for="{{ $name }}" class="tw:mb-0 tw:text-sm tw:leading-5 tw:font-medium tw:text-gray-700 tw:dark:text-gray-400">
            {{ $label }}
        </label>
    @endif
    <select id="{{ $name }}" class="tw:p-5px tw:text-sm tw:bg-gray-50 tw:border tw:border-gray-300 tw:text-gray-900 tw:rounded-lg tw:focus:ring-blue-500 tw:focus:border-blue-500 tw:p-1 tw:dark:bg-gray-700 tw:dark:border-gray-600 tw:dark:placeholder-gray-400 tw:dark:text-white tw:dark:focus:ring-blue-500 tw:dark:focus:border-blue-500">
        <option hidden disabled @empty($selected)selected @endempty>{{ $hint ?? __('Choose') }}</option>
        @foreach($options ?? [] as $option)
            <option value="{{ $option['value'] ?? $option }}"
                    @if(isset($selected) && $selected == ($option['value'] ?? $option))selected @endif
            >
                @isset($option['icon'])
                    <i class="fa fa-fw fa-lg {{ $option['icon'] }}"></i>
                @endisset
                {{ $option['text'] ?? $option }}
            </option>
        @endforeach
    </select>
</div>
