<?php

declare(strict_types=1);

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Service\OptimalTimeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Valida os horários calculados pelo OptimalTimeResolver.
 *
 * Referência de dias ISO 8601: 1=seg, 2=ter, 3=qua, 4=qui, 5=sex, 6=sáb, 7=dom
 * Datas de referência (2026-06-01 = segunda-feira):
 *   2026-06-01 seg | 2026-06-02 ter | 2026-06-03 qua | 2026-06-04 qui
 *   2026-06-05 sex | 2026-06-06 sáb | 2026-06-07 dom | 2026-06-08 seg
 *
 * Janelas de fallback por perfil (sem restrict_business_hours):
 *   B2B     (empresa preenchida ou domínio corporativo) → 9h–11h  seg–sex
 *   B2C     (domínio de email gratuito)                 → 17h–19h seg–sex
 *   Unknown (sem empresa e sem email identificável)     → 10h–11h seg–sex
 *
 * Todos os perfis usam apenas seg–sex: HSM é intrusivo, sem envio em fim de semana.
 */
class OptimalTimeResolverTest extends TestCase
{
    private MessageLogRepository&MockObject $repo;
    private CoreParametersHelper&MockObject $params;
    private OptimalTimeResolver $resolver;

    protected function setUp(): void
    {
        $this->repo   = $this->createMock(MessageLogRepository::class);
        $this->params = $this->createMock(CoreParametersHelper::class);
        $this->params->method('get')->with('default_timezone', 'UTC')->willReturn('UTC');

        $this->resolver = new OptimalTimeResolver($this->repo, $this->params);
    }

    // ─── helpers ───────────────────────────────────────────────────────────────

    private function makeContact(
        int $id = 1,
        ?string $timezone = null,
        ?string $company = null,
        ?string $email = null,
    ): Lead&MockObject {
        $mock = $this->createMock(Lead::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getTimezone')->willReturn($timezone);
        $mock->method('getCompany')->willReturn($company);
        $mock->method('getEmail')->willReturn($email);

        return $mock;
    }

    /** @param array<int, \DateTime> $readDates */
    private function stubReads(array $readDates): void
    {
        $rows = array_map(fn (\DateTime $d) => ['dateRead' => $d], $readDates);
        $this->repo->method('getReadInteractionsByLead')->willReturn($rows);
    }

    private function makeReads(int $count, string $dateTimeStr): array
    {
        return array_fill(0, $count, new \DateTime($dateTimeStr, new \DateTimeZone('UTC')));
    }

    private function resolve(Lead $contact, bool $bh, string $nowStr): \DateTime
    {
        return $this->resolver->resolve($contact, $bh, new \DateTime($nowStr, new \DateTimeZone('UTC')));
    }

    // =========================================================================
    // FALLBACK B2B: empresa preenchida → 9h–11h seg–sex
    // contactId=1: 1%120=1min → slot 9:01
    // =========================================================================

    public function testFallbackB2BByCompanyBeforeWindowSchedulesToday(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1, company: 'Acme Corp');

        $result = $this->resolve($contact, false, '2026-06-01 07:30:00'); // seg 07:30

        $this->assertSame('2026-06-01', $result->format('Y-m-d'), 'Deve ser hoje');
        $this->assertSame('9', $result->format('G'),               'Deve ser às 9h');
    }

    public function testFallbackB2BByCompanyInsideWindowSchedulesNextDay(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1, company: 'Acme Corp');

        $result = $this->resolve($contact, false, '2026-06-01 10:00:00'); // seg 10h — dentro da janela 9–11

        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Janela passou → amanhã (terça)');
        $this->assertSame('9', $result->format('G'));
    }

    public function testFallbackB2BByCompanyOnWeekendSchedulesMonday(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1, company: 'Acme Corp');

        $result = $this->resolve($contact, false, '2026-06-06 08:00:00'); // sáb 08h

        $this->assertSame('2026-06-08', $result->format('Y-m-d'), 'Fim de semana → segunda');
        $this->assertSame('1', $result->format('N'));
        $this->assertSame('9', $result->format('G'));
    }

    public function testFallbackB2BByDomainCorporateEmail(): void
    {
        $this->stubReads([]);
        // Sem empresa mas domínio corporativo → B2B
        $contact = $this->makeContact(id: 1, email: 'joao@minhaempresa.com.br');

        $result = $this->resolve($contact, false, '2026-06-01 07:00:00');

        $this->assertSame('9', $result->format('G'), 'Domínio corporativo → janela B2B 9h');
    }

    // =========================================================================
    // FALLBACK B2C: email gratuito → 17h–19h seg–sex
    // contactId=1: 1%120=1min → slot 17:01
    // =========================================================================

    public function testFallbackB2CBeforeWindowSchedulesToday(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1, email: 'maria@gmail.com');

        $result = $this->resolve($contact, false, '2026-06-01 16:00:00'); // seg 16h

        $this->assertSame('2026-06-01', $result->format('Y-m-d'), 'Antes das 17h → hoje');
        $this->assertSame('17', $result->format('G'),              'Deve ser às 17h');
    }

    public function testFallbackB2CInsideWindowSchedulesNextDay(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1, email: 'maria@hotmail.com');

        $result = $this->resolve($contact, false, '2026-06-01 18:00:00'); // seg 18h — dentro da janela

        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Janela passou → amanhã (terça)');
        $this->assertSame('17', $result->format('G'));
    }

    public function testFallbackB2COnWeekendSchedulesMonday(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1, email: 'pedro@uol.com.br');

        $result = $this->resolve($contact, false, '2026-06-06 10:00:00'); // sáb 10h

        $this->assertSame('2026-06-08', $result->format('Y-m-d'), 'Fim de semana → segunda');
        $this->assertSame('17', $result->format('G'));
    }

    public function testFallbackB2CFreeEmailDomainsRecognized(): void
    {
        $this->stubReads([]);

        $domains = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com.br', 'bol.com.br', 'uol.com.br'];
        foreach ($domains as $domain) {
            $contact = $this->makeContact(id: 1, email: "user@{$domain}");
            $result  = $this->resolve($contact, false, '2026-06-01 07:00:00');
            $this->assertSame('17', $result->format('G'), "Domínio {$domain} deve ser B2C → 17h");
        }
    }

    // =========================================================================
    // FALLBACK Unknown: sem empresa, sem email identificável → 10h–11h seg–sex
    // contactId=1: 1%60=1min → slot 10:01
    // =========================================================================

    public function testFallbackUnknownNoCompanyNoEmailBeforeWindow(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1); // sem company, sem email

        $result = $this->resolve($contact, false, '2026-06-01 09:00:00'); // seg 09h

        $this->assertSame('2026-06-01', $result->format('Y-m-d'), 'Antes das 10h → hoje');
        $this->assertSame('10', $result->format('G'),              'Deve ser às 10h');
    }

    public function testFallbackUnknownInsideWindowSchedulesNextDay(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1);

        $result = $this->resolve($contact, false, '2026-06-01 11:00:00'); // seg 11h — passou

        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Janela passou → amanhã (terça)');
        $this->assertSame('10', $result->format('G'));
    }

    public function testFallbackUnknownOnWeekendSchedulesMonday(): void
    {
        $this->stubReads([]);
        $contact = $this->makeContact(id: 1);

        $result = $this->resolve($contact, false, '2026-06-06 08:00:00'); // sáb

        $this->assertSame('2026-06-08', $result->format('Y-m-d'), 'Fim de semana → segunda');
        $this->assertSame('10', $result->format('G'));
    }

    // =========================================================================
    // Distribuição dentro da janela: sem thundering herd
    // Usando perfil B2B (janela 120min) para verificar dispersão
    // id=1 → 9:01 | id=100 → 10:40 | id=121 → 121%120=1 → 9:01 (mesmo que id=1)
    // =========================================================================

    public function testFallbackSlotsAreDifferentPerContact(): void
    {
        $this->stubReads([]);

        $now      = '2026-06-01 07:00:00';
        $contact1 = $this->makeContact(id: 1,   company: 'Corp');
        $contact2 = $this->makeContact(id: 100,  company: 'Corp');
        $contact3 = $this->makeContact(id: 121,  company: 'Corp'); // 121%120=1 → mesmo slot de id=1

        $r1 = $this->resolve($contact1, false, $now);
        $r2 = $this->resolve($contact2, false, $now);
        $r3 = $this->resolve($contact3, false, $now);

        $this->assertNotSame($r1->format('H:i'), $r2->format('H:i'), 'id=1 e id=100 devem ter slots diferentes');
        $this->assertSame($r1->format('H:i'),    $r3->format('H:i'), 'id=1 e id=121 devem ter o mesmo slot (121%120=1)');
    }

    // =========================================================================
    // FALLBACK: restrict_business_hours = true → 8h–12h seg–sex (independe do perfil)
    // =========================================================================

    public function testFallbackBHWeekdayBeforeWindowSchedulesTodayAt8h(): void
    {
        $this->stubReads([]);

        $result = $this->resolve($this->makeContact(), true, '2026-06-01 07:00:00'); // seg 07h

        $this->assertSame('2026-06-01', $result->format('Y-m-d'), 'Deve ser hoje (segunda)');
        $this->assertSame('8', $result->format('G'),              'Deve ser às 8h');
    }

    public function testFallbackBHWeekdayInsideWindowSchedulesNextDayAt8h(): void
    {
        $this->stubReads([]);

        $result = $this->resolve($this->makeContact(), true, '2026-06-01 10:00:00'); // seg 10h

        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Deve ser amanhã (terça)');
        $this->assertSame('8', $result->format('G'),              'Deve ser às 8h');
    }

    public function testFallbackBHWeekdayAfterWindowSchedulesNextDayAt8h(): void
    {
        $this->stubReads([]);

        $result = $this->resolve($this->makeContact(), true, '2026-06-01 14:00:00'); // seg 14h

        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Deve ser amanhã (terça)');
        $this->assertSame('8', $result->format('G'),              'Deve ser às 8h');
    }

    public function testFallbackBHSaturdaySchedulesNextMondayAt8h(): void
    {
        $this->stubReads([]);

        $result = $this->resolve($this->makeContact(), true, '2026-06-06 10:00:00'); // sáb 10h

        $this->assertSame('2026-06-08', $result->format('Y-m-d'), 'Deve ser segunda-feira');
        $this->assertSame('1', $result->format('N'),              'Dia ISO deve ser 1 (seg)');
        $this->assertSame('8', $result->format('G'),              'Deve ser às 8h');
    }

    public function testFallbackBHSundaySchedulesNextMondayAt8h(): void
    {
        $this->stubReads([]);

        $result = $this->resolve($this->makeContact(), true, '2026-06-07 10:00:00'); // dom 10h

        $this->assertSame('2026-06-08', $result->format('Y-m-d'), 'Deve ser segunda-feira');
        $this->assertSame('8', $result->format('G'));
    }

    public function testFallbackBHFridayAfternoonSchedulesNextMondayAt8h(): void
    {
        $this->stubReads([]);

        $result = $this->resolve($this->makeContact(), true, '2026-06-05 15:00:00'); // sex 15h

        $this->assertSame('2026-06-08', $result->format('Y-m-d'), 'Sex tarde → próxima segunda');
        $this->assertSame('8', $result->format('G'));
    }

    public function testFallbackBHIgnoresProfileSegmentation(): void
    {
        $this->stubReads([]);

        // B2C com restrict_business_hours → deve usar 8h (BH), não 17h (B2C)
        $contact = $this->makeContact(id: 1, email: 'joao@gmail.com');
        $result  = $this->resolve($contact, true, '2026-06-01 07:00:00');

        $this->assertSame('8', $result->format('G'), 'restrict_business_hours sobrescreve segmentação de perfil');
    }

    // =========================================================================
    // COM DADOS WA: ≥ 5 reads → calcula horário/dia exclusivamente pelo histórico HSM
    //
    // Setup: 5 reads às 12h nas quartas-feiras (2026-05-27 = quarta)
    //   median(hour) = 12 → hourStart = (12+23)%24 = 11, hourEnd = 13
    //   bestDays = [3] (quarta)
    // =========================================================================

    public function testWithDataSchedulesAtOptimalDayAndHour(): void
    {
        $this->stubReads($this->makeReads(5, '2026-05-27 12:00:00'));

        // now = segunda 15h → próxima quarta às 11h
        $result = $this->resolve($this->makeContact(), false, '2026-06-01 15:00:00');

        $this->assertSame('2026-06-03', $result->format('Y-m-d'), 'Deve ser próxima quarta');
        $this->assertSame('3', $result->format('N'),              'Dia ISO deve ser 3 (qua)');
        $this->assertSame('11', $result->format('G'),             'Deve ser às 11h (mediana-1h)');
    }

    public function testWithDataAlreadyInOptimalWindowSendsNow(): void
    {
        $this->stubReads($this->makeReads(5, '2026-05-27 12:00:00'));

        // now = quarta 11:30 (dentro da janela [11,13) e dia=3)
        $now    = new \DateTime('2026-06-03 11:30:00', new \DateTimeZone('UTC'));
        $result = $this->resolver->resolve($this->makeContact(), false, $now);

        $this->assertLessThanOrEqual($now, $result, 'Já no horário ideal: deve enviar agora');
        $this->assertSame('2026-06-03', $result->format('Y-m-d'));
    }

    public function testWithDataPastOptimalWindowSchedulesNextWeek(): void
    {
        $this->stubReads($this->makeReads(5, '2026-05-27 12:00:00'));

        // now = quarta 14h (passou a janela [11,13)) → próxima quarta às 11h
        $result = $this->resolve($this->makeContact(), false, '2026-06-03 14:00:00');

        $this->assertSame('2026-06-10', $result->format('Y-m-d'), 'Deve ser próxima quarta (+7 dias)');
        $this->assertSame('11', $result->format('G'));
    }

    public function testWithDataMixedDaysPicksMostFrequent(): void
    {
        // 3 reads na quarta + 2 na segunda → bestDays deve ser [3, 1]
        $wed = $this->makeReads(3, '2026-05-27 10:00:00'); // quarta
        $mon = $this->makeReads(2, '2026-05-25 10:00:00'); // segunda
        $this->stubReads(array_merge($wed, $mon));

        // median(hour) = 10, hourStart=(10+23)%24=9
        $result = $this->resolve($this->makeContact(), false, '2026-06-04 15:00:00'); // quinta

        $this->assertContains(
            (int) $result->format('N'),
            [1, 3],
            'Deve agendar para segunda ou quarta (dias mais frequentes no HSM)'
        );
        $this->assertSame('9', $result->format('G'), 'Deve ser às 9h (mediana-1h de leituras às 10h)');
    }

    // =========================================================================
    // COM DADOS WA + restrict_business_hours = true
    // =========================================================================

    public function testWithDataOutsideBusinessHoursIsAdjusted(): void
    {
        // 5 reads às 22h nos sábados → hourStart=21, bestDays=[6] (sáb)
        $this->stubReads($this->makeReads(5, '2026-05-30 22:00:00')); // sábado 2026-05-30

        // now = segunda 08h → findNextSlot encontra sábado 21h → applyBusinessHours → segunda 08h
        $result = $this->resolve($this->makeContact(), true, '2026-06-01 08:00:00');

        $this->assertContains(
            (int) $result->format('N'),
            [1, 2, 3, 4, 5],
            'Resultado deve ser dia útil (seg–sex)'
        );
        $this->assertSame('8', $result->format('G'), 'Deve ser às 8h (início do expediente)');
    }

    public function testWithDataInsideBusinessHoursNotAdjusted(): void
    {
        // 5 reads às 10h nas terças → hourStart=9, bestDays=[2]
        $this->stubReads($this->makeReads(5, '2026-05-26 10:00:00')); // terça

        // now = segunda 15h → próxima terça às 9h (dentro do expediente 8h–18h)
        $result = $this->resolve($this->makeContact(), true, '2026-06-01 15:00:00');

        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Deve ser terça');
        $this->assertSame('2', $result->format('N'),              'ISO 2 = terça');
        $this->assertSame('9', $result->format('G'),              'Às 9h — dentro do expediente, sem ajuste');
    }

    // =========================================================================
    // Contagem mínima: exatamente 4 reads → fallback; 5 reads → usa dados WA
    // =========================================================================

    public function testFourReadsFallsBack(): void
    {
        $this->stubReads($this->makeReads(4, '2026-05-27 12:00:00'));

        // 4 reads WA → fallback unknown (sem company/email) → 10h
        // now = seg 20h → 10h passou → amanhã (terça)
        $result = $this->resolve($this->makeContact(), false, '2026-06-01 20:00:00');

        $this->assertSame('10', $result->format('G'),              'Com 4 reads WA: deve usar fallback unknown (10h)');
        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Deve ser amanhã (terça)');
    }

    public function testFiveReadsUsesData(): void
    {
        $this->stubReads($this->makeReads(5, '2026-05-27 12:00:00')); // qua 12h

        $result = $this->resolve($this->makeContact(), false, '2026-06-01 20:00:00'); // seg 20h

        $this->assertSame('11', $result->format('G'), 'Com 5 reads WA: deve usar dados HSM (11h)');
        $this->assertSame('3', $result->format('N'),  'Deve ser quarta (dia das leituras HSM)');
    }

    // =========================================================================
    // Timezone do contato
    // =========================================================================

    public function testContactTimezoneIsRespected(): void
    {
        $this->stubReads([]);

        // Contato em America/Sao_Paulo (UTC-3), perfil unknown → janela 10h–11h
        // now em UTC = 11:00 → em Sao_Paulo = 08:00 → antes das 10h → agenda às 10h SP hoje
        $params = $this->createMock(CoreParametersHelper::class);
        $params->method('get')->willReturn('UTC');
        $resolver = new OptimalTimeResolver($this->repo, $params);

        $contact = $this->makeContact(id: 1, timezone: 'America/Sao_Paulo');
        $nowUtc  = new \DateTime('2026-06-01 11:00:00', new \DateTimeZone('UTC')); // 08h SP

        $result = $resolver->resolve($contact, false, $nowUtc);

        $this->assertSame('America/Sao_Paulo', $result->getTimezone()->getName());
        $this->assertSame('10', $result->format('G'), 'Deve agendar às 10h no fuso do contato');
    }
}
