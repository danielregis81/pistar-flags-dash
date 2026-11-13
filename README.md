# pistar-flags-dash

Mostra a **bandeira do país**, o **nome do operador** e o **código do país**
(com o nome por extenso ao passar o mouse) na lista *Last Heard* do dashboard
do **Pi-Star oficial** — de um jeito **leve o suficiente pra rodar num
Raspberry Pi Zero W**.

![Exemplo do Last Heard com bandeiras, nomes e codigo do pais](docs/screenshot.png)

Cada linha ganha a bandeira do país, o nome do operador e o código do país em
3 letras (ex.: `(BRA)`, `(USA)`, `(IND)`). Passando o mouse no código, aparece
o nome do país por extenso — no idioma do dashboard.

---

## Por que isso existe

Eu rodo meu hotspot num **Raspberry Pi Zero W** (ARMv6, single-core). Queria
deixar o dashboard mais informativo — bandeira do país, nome de quem está
falando — igual aos dashboards "turbinados" que a gente vê por aí.

O caminho natural seria instalar o **WPSD** (W0CHP-PiStar-Dash), que é
excelente e tem tudo isso embutido. Só que o próprio autor do WPSD avisa, com
todas as letras, que ele **não foi feito pra hardware fraco como o Pi Zero W**
— roda lento e pesado nesse tipo de placa. E é verdade: o WPSD calcula e exibe
*muita* coisa em tempo real, o que é demais pro Pi Zero.

Eu não queria o pacote inteiro. Queria só **três coisas**: bandeira, nome e
país. Então, em vez de trocar de hardware ou sofrer com um dashboard lento,
fiz este patch mínimo: ele adiciona exatamente essas informações ao dashboard
**oficial** do Pi-Star, sem o peso de um fork completo.

**Resumindo:** se você está na mesma situação que eu — Pi-Star oficial,
hardware modesto (Pi Zero W e afins), e só quer bandeira + nome + país sem
trocar o dashboard inteiro — este projeto é pra você.

> ⚠️ **É só pra o Pi-Star OFICIAL.** Se você já usa WPSD ou outro fork, não
> precisa disto (eles já têm essa funcionalidade). O instalador detecta forks
> e **aborta sem mexer em nada**.

---

## O que ele faz

- **Bandeira do país** ao lado do indicativo (derivada do prefixo, tabela de
  prefixos ITU — cobertura mundial, ~250 países).
- **Nome do operador** (lido do `DMRIds.dat`, a mesma base que o Pi-Star já
  baixa).
- **Código do país em 3 letras** no lugar do antigo link "(GPS)" — que levava
  pro aprs.fi e, na prática, não servia pra muita coisa. Ex.: `(BRA)`, `(USA)`.
- **Tooltip com o nome do país por extenso**, no idioma do dashboard:
  se o Pi-Star está em inglês mostra "Brazil"; em português, "Brasil".

### Por que é leve (importa no Pi Zero)

- A bandeira é uma **imagem SVG estática** servida pelo próprio webserver —
  custo de CPU praticamente zero.
- O `DMRIds.dat` (~6-7 MB) é lido **uma única vez** e os pares
  `indicativo → nome` ficam num cache em disco, refeito só quando o arquivo
  muda. Nos refreshes de 1,5 s, lê-se o cache — não o arquivo inteiro.
- O resto (país, código, tooltip) é resolvido em memória, sem rede.

Ou seja: nada do processamento pesado que torna os forks completos lentos no
Pi Zero.

---

## Instalação

No seu hotspot, via SSH:

```bash
git clone https://github.com/danielregis81/pistar-flags-dash.git
cd pistar-flags-dash
sudo ./install.sh
```

Depois, **Ctrl+F5** no dashboard (hard refresh pra limpar o cache do
navegador).

### Comandos

```bash
sudo ./install.sh              # instala (ou atualiza)
sudo ./install.sh --status     # mostra o estado atual
sudo ./install.sh --uninstall  # remove e restaura o dashboard original
```

O instalador cuida de tudo:
- detecta o dashboard e **aborta se for um fork** (WPSD etc.);
- monta o sistema de arquivos como leitura/escrita (`rpi-rw` se existir, senão
  `mount -o remount,rw /`) e volta pra somente-leitura ao terminar;
- faz **backup** do `lh.php` original antes de qualquer mudança;
- aplica o patch de forma **não-destrutiva** (injeta só o necessário, não troca
  o arquivo) e valida a sintaxe com `php -l`;
- instala a **reaplicação automática pós-update** (veja abaixo).

---

## Sobrevive aos updates do Pi-Star

O dashboard do Pi-Star é um repositório git, e um `pistar-update` pode reverter
o `lh.php`. Pra isso não derrubar a customização, o projeto instala um
`cron.hourly` que **reaplica o patch sozinho** caso detecte que ele sumiu.
Quando não há nada a fazer, ele sai na hora, sem tocar no disco.

---

## Desinstalação

```bash
sudo ./install.sh --uninstall
```

Restaura o `lh.php` original (do backup), remove as bandeiras, o
`lh_enrich.php`, o script de reaplicação e o cron. **Ctrl+F5** depois.

---

## Limitações (sendo honesto)

- **Nome** vem do `DMRIds.dat` (base DMR). Indicativos que não estiverem nessa
  base aparecem só com a bandeira — sem nome. Nenhum erro, só não tem o dado.
- **Bandeira/país** dependem do prefixo do indicativo (tabela ITU). Cobre a
  grande maioria, mas se aparecer um prefixo não mapeado a linha fica sem
  bandeira (sem quebrar nada). PRs de novos prefixos são bem-vindos.
- Pensado e testado no **Pi-Star oficial** (v4.2.6, Pi Zero W). Não testei em
  todas as versões/forks — e em forks ele nem instala, de propósito.

---

## Estrutura

```
pistar-flags-dash/
├── install.sh              # instala / desinstala / status
├── lib/patch-lh.sh         # patch nao-destrutivo e idempotente do lh.php
├── src/
│   ├── lh_enrich.php        # funcoes: pais (ITU), nome (DMRIds+cache), tooltip
│   └── flags/               # bandeiras SVG (ISO-3166-1 alpha-2) + INDEX
├── pistar-lh-reapply        # reaplicacao pos-update
├── cron/zz-pistar-lh-reapply # gatilho cron.hourly
├── docs/screenshot.png
├── README.md
└── LICENSE                  # GPL-3.0
```

---

## Créditos

- **Andy Taylor (MW0MWZ)** — criador do Pi-Star, que tornou tudo isso possível.
- **Hampus Borgos** — [country-flags](https://github.com/hampusborgos/country-flags),
  as bandeiras SVG (domínio público).
- A ideia de bandeira por indicativo é inspirada em forks do Pi-Star (como o
  WPSD/W0CHP) e em projetos como o `xlxd`.

Este **não** é um projeto oficial do Pi-Star. Use por sua conta e risco.

## Licença

**GPL-3.0** — veja [LICENSE](LICENSE). O Pi-Star é GPL; este é um trabalho
derivado e segue a mesma licença.
