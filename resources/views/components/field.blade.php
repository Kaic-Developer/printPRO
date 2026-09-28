@props(['name', 'label', 'type' => 'text', 'value' => '', 'required' => false, 'autocomplete' => null])
<div class="field"><label for="{{ $name }}">{{ $label }} @if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
<input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}" @required($required) @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror {{ $attributes }}>
@error($name)<p class="field-error" id="{{ $name }}-error">{{ $message }}</p>@enderror</div>
