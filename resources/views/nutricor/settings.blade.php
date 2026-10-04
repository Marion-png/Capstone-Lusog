{{--
    Account settings in the Nutrition Coordinator's shell.

    That role's pages are built on their own layout rather than the shared
    LUSOG shell, so this is a thin host around the **same** body partial the
    other six roles get — one reading, two shells, never a second copy of the
    panels that could drift from the first.
--}}
@extends('nutricor.layout')

@section('title', 'Settings')
@section('crumb', 'Settings')
@section('page_title', 'Your Settings')
@section('page_subtitle', 'The account you are signed in with, and your password.')

@section('content')
    <style>{!! file_get_contents(resource_path('css/settings.css')) !!}</style>
    @include('partials.settings-panels')
@endsection
