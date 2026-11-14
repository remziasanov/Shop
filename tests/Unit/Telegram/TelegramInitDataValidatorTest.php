<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_miniapp.php';

use App\Services\Telegram\MiniApp\InvalidInitDataException;
use App\Services\Telegram\MiniApp\TelegramInitDataValidator;

/**
 * TM-02: the signature check of the initData string. The fixture below is a realistic-looking string (user JSON with escaped
 * slashes and Cyrillic, plus signs, `signature`, `start_param`) SELF-SIGNED with a fake token; it holds no real secret and
 * is NOT captured from a real Telegram client. A real capture (throwaway bot, token revoked afterwards) is a manual step.
 */
const TG_FIXTURE_TOKEN = '7000000000:AAFakeTokenForTestsOnly_0123456789abcdefgh';

const TG_FIXTURE = 'query_id=AAHdF6IQAAAAAN0XohDhrOrc&user=%7B%22id%22%3A279058397%2C%22first_name%22%3A%22%D0%92%D0%BB%D0%B0%D0%B4%D0%B8%D1%81%D0%BB%D0%B0%D0%B2%22%2C%22last_name%22%3A%22Kibenko+Jr.%22%2C%22username%22%3A%22vdk.frost%22%2C%22language_code%22%3A%22ru%22%2C%22is_premium%22%3Atrue%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%5C%2F%5C%2Ft.me%5C%2Fi%5C%2Fuserpic%5C%2F320%5C%2Fabcdefg-HIJ_klmnop.svg%22%7D&auth_date=1662771648&start_param=p_15&chat_type=sender&chat_instance=8428209589180549439&signature=zL-ucjNyREiHDE8aihFwpfR9aggP2xiAo3NSpfe-p7IbCisNlDKlo7Kb6G083DPl2JUNbkgGnZfn_UXANOz-CQ&hash=d8f279ff0b1baac202c1a3c0db19da59ab27d52428361f0f132380ce97cf380a';

function tgValidate(string $initData, string $token = TG_TEST_TOKEN, int $ttl = 3600, bool $checkAge = true): ?string
{
    try {
        (new TelegramInitDataValidator)->validate($initData, $token, $ttl, $checkAge);
    } catch (InvalidInitDataException $e) {
        return $e->reason;
    }

    return null;
}

it('accepts a valid signature and reads the user (TM-02)', function (): void {
    $data = (new TelegramInitDataValidator)->validate(tgInitData(['start_param' => 'p_9'], ['id' => 42, 'first_name' => 'Анна', 'allows_write_to_pm' => true]), TG_TEST_TOKEN, 3600);

    expect($data->userId)->toBe(42)
        ->and($data->firstName)->toBe('Анна')
        ->and($data->allowsWriteToPm)->toBeTrue()
        ->and($data->startParam)->toBe('p_9');
});

it('verifies a realistic self-signed fixture in the shape of a client string: signature, dots in names, escaped slashes (not a real capture)', function (): void {
    $data = (new TelegramInitDataValidator)->validate(TG_FIXTURE, TG_FIXTURE_TOKEN, 3600, checkAge: false);

    expect($data->userId)->toBe(279058397)
        ->and($data->lastName)->toBe('Kibenko Jr.')
        ->and($data->username)->toBe('vdk.frost')
        ->and($data->startParam)->toBe('p_15');
});

it('rejects a changed field, a foreign token and a missing hash (TM-02)', function (): void {
    $valid = tgInitData();

    expect(tgValidate(str_replace('Ivan', 'Eve', $valid)))->toBe(InvalidInitDataException::BAD_SIGNATURE)
        ->and(tgValidate(tgInitData(token: '999:OTHER-token')))->toBe(InvalidInitDataException::BAD_SIGNATURE)
        ->and(tgValidate(preg_replace('/&hash=[0-9a-f]+/', '', $valid) ?? ''))->toBe(InvalidInitDataException::NO_HASH)
        ->and(tgValidate(TG_FIXTURE, TG_TEST_TOKEN, checkAge: false))->toBe(InvalidInitDataException::BAD_SIGNATURE)
        ->and(tgValidate($valid, ''))->toBe(InvalidInitDataException::MISSING_BOT);
});

it('rejects an expired auth_date and a date from the future, only when the age is checked (TM-02)', function (): void {
    $old = tgInitData(authDate: time() - 7200);
    $future = tgInitData(authDate: time() + 3600);
    $skew = tgInitData(authDate: time() + 120);

    expect(tgValidate($old))->toBe(InvalidInitDataException::EXPIRED)
        ->and(tgValidate($old, checkAge: false))->toBeNull()
        ->and(tgValidate($future))->toBe(InvalidInitDataException::FUTURE)
        ->and(tgValidate($skew))->toBeNull();
});

it('requires a user with an id (TM-02)', function (): void {
    expect(tgValidate(tgInitData(user: null)))->toBe(InvalidInitDataException::NO_USER)
        ->and(tgValidate(tgInitData(user: ['first_name' => 'No id'])))->toBe(InvalidInitDataException::NO_USER)
        ->and(tgValidate(tgInitData(user: ['id' => '12'])))->toBe(InvalidInitDataException::NO_USER);
});

it('keeps the signature field in the data check string (TM-02)', function (): void {
    $withSignature = tgInitData(['signature' => 'abc_DEF-123']);

    expect(tgValidate($withSignature))->toBeNull()
        ->and(tgValidate(str_replace('abc_DEF-123', 'abc_DEF-124', $withSignature)))->toBe(InvalidInitDataException::BAD_SIGNATURE);
});

it('does not run parse_str: dots and brackets in keys survive and a repeated key is refused', function (): void {
    expect(tgValidate(tgInitData(['a.b[c]' => '1'])))->toBeNull()
        ->and(tgValidate(tgInitData().'&auth_date=1'))->toBe(InvalidInitDataException::MALFORMED)
        ->and(tgValidate(''))->toBe(InvalidInitDataException::MALFORMED)
        ->and(tgValidate('garbage'))->toBe(InvalidInitDataException::MALFORMED);
});
