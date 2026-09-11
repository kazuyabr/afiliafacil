<?php

use Phinx\Migration\AbstractMigration;

class RenamePlansAndSetAdSpyQuotas extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE plans SET name = 'VSL Start', label = 'Páginas de VSL com delay' WHERE id = 'vsl' AND name = 'VSL'");
        $this->execute("UPDATE plans SET name = 'Afiliado Pro', label = '5 páginas, 2 domínios' WHERE id = 'essencial' AND name = 'Essencial'");
        $this->execute("UPDATE plans SET name = 'Master Elite', label = 'Tudo ilimitado + integrações' WHERE id = 'master' AND name = 'Master'");

        $this->execute("UPDATE plans SET max_adspy_searches = 3, max_ai_analyses = 3 WHERE id = 'trial' AND max_adspy_searches = 0 AND max_ai_analyses = 0");
        $this->execute("UPDATE plans SET max_adspy_searches = 30, max_ai_analyses = 10 WHERE id = 'essencial' AND max_adspy_searches = 0 AND max_ai_analyses = 0");
        $this->execute("UPDATE plans SET max_adspy_searches = 300, max_ai_analyses = 100 WHERE id = 'master' AND max_adspy_searches = 0 AND max_ai_analyses = 0");
        $this->execute("UPDATE plans SET max_adspy_searches = -1, max_ai_analyses = -1 WHERE id = 'premium' AND max_adspy_searches = 0 AND max_ai_analyses = 0");
    }

    public function down(): void
    {
    }
}
