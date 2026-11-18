<x-layouts.app title="Masuk">
<main class="login">
    <div class="login-box">
        <span class="brand"><x-icon name="food"/>petstation</span>
        <h1>Selamat datang kembali</h1>
        <form method="POST" action="{{ route('login.submit') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
            @if($errors->any())<p class="form-error" role="alert">{{ $errors->first() }}</p>@endif
            <label class="check"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
            <button class="button wide" type="submit">Masuk</button>
        </form>
        <p class="switch">Belum punya akun? <a href="{{ route('register') }}">Daftar</a></p>
        @if(app()->environment('local'))<p class="demo">Demo: demo@petstation.local / petstation-local</p>@endif
    </div>
</main>
</x-layouts.app>
