@extends('layouts.app')

@section('title', 'Enrollment Keys')
@section('subtitle', 'Manage')

@section('content')
    @livewire(App\Livewire\EnrollmentKeyManager::class)
@endsection
