<x-layouts.app title="Daftar">
<main class="login">
    <div class="login-box">
        <span class="brand"><x-icon name="food"/>petstation</span>
        <h1>Buat akun</h1>
        <form method="POST" action="{{ route('register.submit') }}">
            @csrf
            <label for="name">Nama</label>
            <input id="name" name="name" value="{{ old('name') }}" maxlength="80" required autofocus autocomplete="name">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" minlength="10" required autocomplete="new-password" placeholder="Minimal 10 karakter">
            <label for="password_confirmation">Ulangi kata sandi</label>
            <input id="password_confirmation" name="password_confirmation" type="password" minlength="10" required autocomplete="new-password">
            @if($errors->any())<p class="form-error" role="alert">{{ $errors->first() }}</p>@endif
            <button class="button wide" type="submit">Daftar</button>
        </form>
        <p class="switch">Sudah punya akun? <a href="{{ route('login') }}">Masuk</a></p>
    </div>
</main>
</x-layouts.app>
