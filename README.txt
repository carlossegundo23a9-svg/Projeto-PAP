ESTEL SGP - Guia de Instalação (Passo a Passo)

1) Requisitos
- Windows com XAMPP (Apache + MySQL) instalado
- PHP 8.1 ou superior
- Composer 2.x
- Extensões PHP ativas: ctype, dom, fileinfo, filter, gd, hash, iconv, libxml, mbstring, simplexml, xml, xmlreader, xmlwriter, zip, zlib, openssl

2) Colocar o projeto na pasta do XAMPP
- Caminho esperado: C:\xampp\htdocs\projeto
- Abrir terminal (PowerShell) dentro dessa pasta

3) Instalar dependências PHP
- Executar:
  composer install
- Se a pasta vendor/ já existir e estiver completa, este passo pode ser opcional

4) Criar a base de dados
- O schema principal está em: database\estelsgp_schema.sql
- Opção A (phpMyAdmin):
  1. Abrir http://localhost/phpmyadmin
  2. Importar o ficheiro database\estelsgp_schema.sql
- Opção B (linha de comandos):
  mysql -u root < database\estelsgp_schema.sql


5) Configurar variáveis de ambiente
- Criar .env a partir do exemplo:
  Copy-Item .env.example .env
- Editar o ficheiro .env com os dados corretos:
  DB_HOST=127.0.0.1
  DB_NAME=estelsgp
  DB_USER=root
  DB_PASS=
- Configurar APP_MAIL_* apenas se for usar envio de email (recuperação de password/notificações)

6) Configurar SMTP apenas no .env
- O projeto usa o ficheiro .env para APP_MAIL_*.
- Evite definir APP_MAIL_* no .htaccess para não sobrepor valores do .env.

7) Criar o primeiro utilizador superadmin
- O sistema não traz utilizador inicial por defeito.
- Gerar hash da password no terminal:
  php -r "echo password_hash('Mudar123!', PASSWORD_DEFAULT), PHP_EOL;"
- Copiar o hash gerado e executar no MySQL/phpMyAdmin:
  INSERT INTO `user` (`nome`, `password`, `email`, `obs`, `ativo`)
  VALUES ('superadmin', 'HASH_GERADO_AQUI', 'admin@local.test', 'superadmin', 1);

8) Iniciar e testar
- No XAMPP Control Panel, iniciar Apache e MySQL
- Abrir no browser:
  http://localhost/projeto/login.php
- Fazer login com o utilizador criado no passo anterior

9) (Opcional) Ativar notificações de atraso por linha de comandos
- Com SMTP configurado, pode testar:
  php scripts\notificar_atrasos.php
- Este comando pode ser agendado (Task Scheduler/Cron) para execução periódica.

10) Problemas comuns
- "Erro na ligação à base de dados": confirmar DB_HOST, DB_NAME, DB_USER e DB_PASS no .env
- "PHPMailer não encontrado": correr novamente composer install
- Emails não enviados: validar APP_MAIL_* e extensão openssl ativa no PHP
