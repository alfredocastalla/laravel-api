Hello {{ $accountName ?: 'BarangayCare user' }},

We received a request to reset the password for your BarangayCare account.

Reset your password using this secure link:
{!! $resetUrl !!}

This link expires in {{ $expiresInMinutes }} minutes and can only be used once. If you did not request a password reset, you can ignore this email. Your password will not change unless the link is used.

BarangayCare
