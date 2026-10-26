@extends('layouts.app')

@section('title', 'Client Builder')
@section('subtitle', 'System')

@section('content')
    @livewire(App\Livewire\ClientBuilder::class)
@endsection
