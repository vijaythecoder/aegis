@extends('layouts.app')

@section('title', 'Project')

@section('content')
<div class="flex-1 overflow-hidden">
    <livewire:project-dashboard :projectId="$projectId" />
</div>
@endsection
