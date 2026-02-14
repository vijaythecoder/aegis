@extends('layouts.app')

@section('title', 'Chat')

@section('content')
    @livewire('chat', ['conversationId' => $conversationId ?? null])
@endsection

@section('input')
@endsection
