<?php
/**
 * P1-5: パスワードポリシー検証
 *
 * 要件:
 *   - 8文字以上
 *   - 英字（a-z, A-Z）を1文字以上含む
 *   - 数字（0-9）を1文字以上含む
 *
 * 不適合の場合は json_error() で即座に400応答して処理を終了する。
 * 適合する場合は何もしない（戻り値なし）。
 *
 * 前提: 呼び出し側で response.php が読み込み済みであること
 */

function validate_password_strength(string $password): void
{
    if (strlen($password) < 8) {
        json_error('WEAK_PASSWORD', 'パスワードは8文字以上で入力してください', 400);
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        json_error('WEAK_PASSWORD', 'パスワードには英字を1文字以上含めてください', 400);
    }
    if (!preg_match('/[0-9]/', $password)) {
        json_error('WEAK_PASSWORD', 'パスワードには数字を1文字以上含めてください', 400);
    }
}
