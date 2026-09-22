<?php

use Phinx\Migration\AbstractMigration;

class RemoveDomainsFromPlanLabels extends AbstractMigration
{
    public function up(): void
    {
        // Domínios deixaram de ser feature da plataforma (não somos hospedagem).
        // Atualiza apenas o label padrão — labels customizados pelo admin são preservados.
        $this->execute("UPDATE plans SET label = '5 páginas' WHERE id = 'essencial' AND label = '5 páginas, 2 domínios'");
    }

    public function down(): void
    {
        $this->execute("UPDATE plans SET label = '5 páginas, 2 domínios' WHERE id = 'essencial' AND label = '5 páginas'");
    }
}
