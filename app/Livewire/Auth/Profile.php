<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Profile extends Component
{
    public string $name = '';
    public string $email = '';
    public string $current_password = '';
    public string $new_password = '';
    public string $new_password_confirmation = '';
    public string $profileSuccess = '';
    public string $passwordSuccess = '';
    public string $passwordError = '';

    public function mount()
    {
        $this->name  = auth()->user()->name;
        $this->email = auth()->user()->email;
    }

    public function updateProfile()
    {
        $this->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . auth()->id(),
        ]);

        auth()->user()->update([
            'name'  => $this->name,
            'email' => $this->email,
        ]);

        $this->profileSuccess = 'Profile updated successfully!';
    }

    public function updatePassword()
    {
        $this->passwordError = '';
        $this->passwordSuccess = '';

        $this->validate([
            'current_password'      => 'required',
            'new_password'          => 'required|min:8|confirmed',
        ]);

        if (!\Illuminate\Support\Facades\Hash::check($this->current_password, auth()->user()->password)) {
            $this->passwordError = 'Current password is incorrect.';
            return;
        }

        auth()->user()->update(['password' => $this->new_password]);

        $this->current_password = '';
        $this->new_password = '';
        $this->new_password_confirmation = '';
        $this->passwordSuccess = 'Password changed successfully!';
    }

    public function render()
    {
        return view('livewire.auth.profile')
            ->layout('components.layouts.app', ['title' => 'My Profile']);
    }
}
