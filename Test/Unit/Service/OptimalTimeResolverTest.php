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

    private function makeContact(int $id = 1, ?string $timezone = null): Lead&MockObject
    {
        $mock = $this->createMock(Lead::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getTimezone')->willReturn($timezone);

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
    // FALLBACK: sem dados suficientes (< 5 reads WA), restrictBusinessHours = false
    // Janela: 9h–18h, qualquer dia; slot determinístico por contactId
    // =========================================================================

    public function testFallbackOpenBeforeWindowSchedulesTodayAt9h(): void
    {
        $this->stubReads([]);   // 0 reads → fallback

        $result = $this->resolve($this->makeContact(), false, '2026-06-01 07:30:00'); // seg 07:30

        $this->assertSame('2026-06-01', $result->format('Y-m-d'), 'Deve ser hoje');
        $this->assertSame('9', $result->format('G'),              'Deve ser às 9h');
    }

    public function testFallbackOpenInsideWindowSchedulesTomorrowAt9h(): void
    {
        $this->stubReads([]);

        // Dentro da janela (11h): 9h de hoje já passou → amanhã às 9h
        $result = $this->resolve($this->makeContact(), false, '2026-06-01 11:00:00');

        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Deve ser amanhã (terça)');
        $this->assertSame('9', $result->format('G'),              'Deve ser às 9h');
    }

    public function testFallbackOpenAfterWindowSchedulesTomorrowAt9h(): void
    {
        $this->stubReads([]);

        $result = $this->resolve($this->makeContact(), false, '2026-06-01 20:00:00'); // seg 20h

        $this->assertSame('2026-06-02', $result->format('Y-m-d'), 'Deve ser amanhã (terça)');
        $this->assertSame('9', $result->format('G'),              'Deve ser às 9h');
    }

    public function testFallbackOpenWorksOnWeekend(): void
    {
        $this->stubReads([]);

        $result = $this->resolve($this->makeContact(), false, '2026-06-06 20:00:00'); // sáb 20h

        // Sem restrict_business_hours: sábado é válido; avança só para domingo às 9h
        $this->assertSame('2026-06-07', $result->format('Y-m-d'), 'Deve ser domingo');
        $this->assertSame('9', $result->format('G'));
    }

    public function testFallbackSlotsAreDifferentPerContact(): void
    {
        $this->stubReads([]);

        // Contatos com IDs diferentes devem receber slots distintos (sem thundering herd)
        $contact1 = $this->makeContact(1);
        $contact2 = $this->makeContact(100);
        $contact3 = $this->makeContact(541); // 541 % 540 = 1 → mesmo slot que contactId=1

        $now = '2026-06-01 07:00:00';
        $r1  = $this->resolve($contact1, false, $now);
        $r2  = $this->resolve($contact2, false, $now);
        $r3  = $this->resolve($contact3, false, $now);

        $this->assertNotSame($r1->format('H:i'), $r2->format('H:i'), 'Contatos 1 e 100 devem ter slots diferentes');
        $this->assertSame($r1->format('H:i'), $r3->format('H:i'),    'Contatos 1 e 541 devem ter o mesmo slot (541%540=1)');
    }

    // =========================================================================
    // FALLBACK: sem dados suficientes, restrictBusinessHours = true
    // Janela: 8h–12h, seg–sex; slot determinístico por contactId
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

        // 4 reads WA → fallback (email/page/form não influenciam o cálculo)
        $result = $this->resolve($this->makeContact(), false, '2026-06-01 20:00:00');

        $this->assertSame('9', $result->format('G'), 'Com 4 reads WA: deve usar fallback distribuído');
        $this->assertSame('2026-06-02', $result->format('Y-m-d'));
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

        // Contato em America/Sao_Paulo (UTC-3)
        // now em UTC = 11:00 → em Sao_Paulo = 08:00 → antes das 9h → agenda às 9h SP
        $params = $this->createMock(CoreParametersHelper::class);
        $params->method('get')->willReturn('UTC');
        $resolver = new OptimalTimeResolver($this->repo, $params);

        $contact = $this->makeContact(1, 'America/Sao_Paulo');
        $nowUtc  = new \DateTime('2026-06-01 11:00:00', new \DateTimeZone('UTC')); // 08h SP

        $result = $resolver->resolve($contact, false, $nowUtc);

        $this->assertSame('America/Sao_Paulo', $result->getTimezone()->getName());
        $this->assertSame('9', $result->format('G'), 'Deve agendar às 9h no fuso do contato');
    }
}
