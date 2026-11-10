{{--
    The warm skin every role shares (css/lusog-skin.css). Every role's rail
    partial includes this, so each app page gets it once, after its page,
    rail and palette sheets. Screen only — printed pages stay plain.
--}}
@once
<style>{!! file_get_contents(resource_path('css/lusog-skin.css')) !!}</style>
@endonce
