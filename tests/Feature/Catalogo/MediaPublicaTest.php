<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogo;

use Tests\TestCase;

final class MediaPublicaTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = public_path('media/pruebas-media-publica');
        @mkdir($this->carpeta, 0777, true);
        file_put_contents($this->carpeta.'/foto.png', 'png');
        file_put_contents($this->carpeta.'/secreto.php', '<?php');
    }

    protected function tearDown(): void
    {
        @unlink($this->carpeta.'/foto.png');
        @unlink($this->carpeta.'/secreto.php');
        @rmdir($this->carpeta);
        parent::tearDown();
    }

    public function test_entrega_la_foto_con_cabecera_cors_para_el_origen_permitido(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:4200']]);

        $this->get('/api/v1/media/pruebas-media-publica/foto.png', ['Origin' => 'http://localhost:4200'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    public function test_no_entrega_otros_tipos_de_archivo_ni_sale_de_la_carpeta(): void
    {
        $this->get('/api/v1/media/pruebas-media-publica/secreto.php')->assertNotFound();
        $this->get('/api/v1/media/..%2F..%2Fcomposer.json')->assertNotFound();
        $this->get('/api/v1/media/no-existe.png')->assertNotFound();
    }
}
