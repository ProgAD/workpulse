<?php
// login id generator
// format: [company initials][first 2 letters of first+last name][join year][4 digit serial]
// example: OIJODO20220001 = Odoo India + JOhn DOe + 2022 + 0001

// sirf letters bacha ke upper 2 letters do, kam pade to X se bharo
function _name_letters(?string $s): string
{
    $s = preg_replace('/[^a-zA-Z]/', '', $s ?? '');
    return str_pad(strtoupper(substr($s, 0, 2)), 2, 'X');
}

function company_initials(string $companyName): string
{
    $words = preg_split('/\s+/', trim($companyName));
    if (count($words) >= 2) {
        // pehle do words ke pehle letters - "Odoo India" -> OI
        return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
    }
    // single word company - pehle 2 letters
    return _name_letters($words[0]);
}

// serial company + year ke hisab se chalta hai (us saal ka kaunsa number joinee hai)
function generate_login_id(int $companyId, string $companyName, string $firstName, ?string $lastName, ?int $year = null): string
{
    $year = $year ?: (int)date('Y');
    $base = company_initials($companyName) . _name_letters($firstName) . _name_letters($lastName) . $year;

    // last 8 chars = YYYYSSSS, usi saal ke sab codes me se max serial nikaalo
    // (regexp mat use karna - hostinger mysql pe collation error deta hai)
    $stmt = db()->prepare(
        "SELECT COALESCE(MAX(CAST(RIGHT(emp_code, 4) AS UNSIGNED)), 0)
         FROM users
         WHERE company_id = ?
           AND SUBSTRING(emp_code, CHAR_LENGTH(emp_code) - 7, 4) = ?"
    );
    $stmt->execute([$companyId, (string)$year]);
    $serial = (int)$stmt->fetchColumn() + 1;

    return $base . str_pad($serial, 4, '0', STR_PAD_LEFT);
}

// pehli baar ka random password, admin employee ko batayega
function generate_temp_password(int $len = 10): string
{
    // confusing chars (0/O, 1/l/I) jaan bujh ke nahi rakhe
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}
