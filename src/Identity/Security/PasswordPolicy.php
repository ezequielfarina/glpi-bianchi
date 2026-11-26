<?php

declare(strict_types=1);

namespace App\Identity\Security;

/**
 * Política de senha alinhada ao NIST SP 800-63B: comprimento > complexidade.
 *
 * - mínimo 12 caracteres (frases-senha são incentivadas), máximo 128;
 * - sem exigir "1 maiúscula + 1 símbolo" (gera senhas previsíveis como Senha@2026);
 * - recusa senhas conhecidas/óbvias, contendo o login ou com poucos caracteres distintos;
 * - recusa reutilizar a senha atual (verificado no AccountService).
 */
final class PasswordPolicy
{
    public const int MIN = 12;
    public const int MAX = 128;

    /** Lista curta de senhas notórias (internacionais + PT-BR + contexto da empresa). */
    private const array COMMON = [
        '123456789012', '1234567890123', 'qwertyuiopas', 'qwerty123456', 'password1234', 'passw0rd1234',
        'senha1234567', 'senhasenha12', 'mudar1234567', 'mudar@123456', 'admin1234567', 'administrador',
        'administrator', 'iloveyou1234', 'abcdefghijkl', 'abc123456789', '111111111111', '000000000000',
        'bianchi12345', 'glpibianchi1', 'glpi12345678', 'inventario123', 'trocar123456', 'brasil123456',
        'welcome12345', 'letmein12345', 'changeme1234', 'p@ssw0rd1234', 'senhaforte123', 'minhasenha12',
    ];

    /** @return string|null mensagem de erro (null = aceita) */
    public function validate(#[\SensitiveParameter] string $password, string $username = '', string $displayName = ''): ?string
    {
        $len = mb_strlen($password);
        if ($len < self::MIN) {
            return \sprintf('Use pelo menos %d caracteres. Dica: uma frase fácil de lembrar, como "café-preto na mesa 7".', self::MIN);
        }
        if ($len > self::MAX || \strlen($password) > PasswordHasher::MAX_BYTES) {
            return \sprintf('Use no máximo %d caracteres.', self::MAX);
        }
        if (\count(array_unique(mb_str_split($password))) < 5) {
            return 'A senha tem caracteres repetidos demais.';
        }
        $lower = mb_strtolower($password);
        if (\in_array($lower, self::COMMON, true) || 1 === preg_match('/^(?:0123456789|1234567890|abcdefghij|qwertyuiop)/', $lower)) {
            return 'Senha muito conhecida — escolha outra.';
        }
        foreach ([$username, ...preg_split('/\s+/', $displayName) ?: []] as $part) {
            $part = mb_strtolower(trim((string) $part));
            if (mb_strlen($part) >= 4 && str_contains($lower, $part)) {
                return 'A senha não pode conter seu login ou seu nome.';
            }
        }

        return null;
    }

    /** Senha temporária (reset pelo administrador): 20 caracteres sem ambiguidades (0/O, 1/l). */
    public function temporary(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 20; ++$i) {
            $out .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
            if (4 === $i % 5 && $i < 19) {
                $out .= '-';
            }
        }

        return $out;
    }
}
