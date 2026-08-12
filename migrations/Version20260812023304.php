<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The board's default colour scheme becomes a setting.
 *
 * It was previously whichever scheme sorted first, so choosing a default and
 * ordering the picker were the same decision. Null keeps that behaviour, which is
 * what every existing board gets until an administrator sets one.
 */
final class Version20260812023304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the default colour scheme setting to board_config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_config ADD default_scheme_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE board_config ADD CONSTRAINT FK_78946BDBF41B50C5 FOREIGN KEY (default_scheme_id) REFERENCES color_schemes (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_78946BDBF41B50C5 ON board_config (default_scheme_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_config DROP FOREIGN KEY FK_78946BDBF41B50C5');
        $this->addSql('DROP INDEX IDX_78946BDBF41B50C5 ON board_config');
        $this->addSql('ALTER TABLE board_config DROP default_scheme_id');
    }
}
