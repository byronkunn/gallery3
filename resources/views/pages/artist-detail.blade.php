@extends('layouts.app', ['title' => $title ?? 'Artist Catalog — Booru.art'])

@section('content')
    @livewire('⚡artist-detail', ['slug' => $slug])
@endsection
