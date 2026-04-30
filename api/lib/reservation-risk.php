<?php
/**
 * 予約リスク判定の共通ヘルパ。
 */

if (!function_exists('reservation_customer_has_near_blacklist')) {
    function reservation_customer_has_near_blacklist($pdo, $storeId, $phone, $email, $name, $excludeCustomerId = null) {
        $phoneDigits = preg_replace('/\D+/', '', (string)$phone);
        $phoneTail = strlen($phoneDigits) >= 8 ? substr($phoneDigits, -8) : '';
        $emailNorm = strtolower(trim((string)$email));
        $nameNorm = preg_replace('/\s+/u', '', mb_strtolower(trim((string)$name), 'UTF-8'));
        $nameComparable = (mb_strlen($nameNorm, 'UTF-8') >= 4);
        if ($phoneTail === '' && $emailNorm === '' && !$nameComparable) return false;

        try {
            $sql = 'SELECT id, customer_name, customer_phone, customer_email
                    FROM reservation_customers
                    WHERE store_id = ? AND is_blacklisted = 1';
            $params = array($storeId);
            if ($excludeCustomerId) {
                $sql .= ' AND id <> ?';
                $params[] = $excludeCustomerId;
            }
            $sql .= ' LIMIT 200';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                if ($emailNorm !== '' && strtolower(trim((string)$row['customer_email'])) === $emailNorm) {
                    return true;
                }
                if ($phoneTail !== '') {
                    $rowDigits = preg_replace('/\D+/', '', (string)$row['customer_phone']);
                    if (strlen($rowDigits) >= 8 && substr($rowDigits, -8) === $phoneTail) {
                        return true;
                    }
                }
                if ($nameComparable) {
                    $rowName = preg_replace('/\s+/u', '', mb_strtolower(trim((string)$row['customer_name']), 'UTF-8'));
                    if ($rowName !== '' && $rowName === $nameNorm) {
                        return true;
                    }
                }
            }
        } catch (PDOException $e) {
            return false;
        }
        return false;
    }
}
