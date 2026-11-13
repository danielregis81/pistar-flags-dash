# pistar-flags-dash

Mostra a **bandeira do paÃ­s**, o **nome do operador** e o **cÃ³digo do paÃ­s**
(com o nome por extenso ao passar o mouse) na lista *Last Heard* do dashboard
do **Pi-Star oficial** â€” de um jeito **leve o suficiente pra rodar num
Raspberry Pi Zero W**.

Antes:

```
PR2CC
PP5RD
```

Depois:

```
ðŸ‡§ðŸ‡· PR2CC  Daniel        (BRA)
ðŸ‡ºðŸ‡¸ N4ONO  Timothy       (USA)
ðŸ‡®ðŸ‡³ VU2ICC Siva          (IND)
```

(passando o mouse em `(BRA)` aparece "Brasil" / "Brazil")

---

## Por que isso existe

Eu rodo meu hotspot num **Raspberry Pi Zero W** (ARMv6, single-core). Queria
deixar o dashboard mais informativo â€” bandeira do paÃ­s, nome de quem estÃ¡
falando â€” igual aos dashboards "turbinados" que a gente vÃª por aÃ­.

O caminho natural seria instalar o **WPSD** (W0CHP-PiStar-Dash), que Ã©
excelente e tem tudo isso embutido. SÃ³ que o prÃ³prio autor do WPSD avisa, com
todas as letras, que ele **nÃ£o foi feito pra hardware fraco como o Pi Zero W**
â€” roda lento e pesado nesse tipo de placa. E Ã© verdade: o WPSD calcula e exibe
*muita* coisa em tempo real, o que Ã© demais pro Pi Zero.

Eu nÃ£o queria o pacote inteiro. Queria sÃ³ **trÃªs coisas**: bandeira, nome e
paÃ­s. EntÃ£o, em vez de trocar de hardware ou sofrer com um dashboard lento,
fiz este patch mÃ­nimo: ele adiciona exatamente essas informaÃ§Ãµes ao dashboard
**oficial** do Pi-Star, sem o peso de um fork completo.

**Resumindo:** se vocÃª estÃ¡ na mesma situaÃ§Ã£o que eu â€” Pi-Star oficial,
hardware modesto (Pi Zero W e afins), e sÃ³ quer bandeira + nome + paÃ­s sem
trocar o dashboard inteiro â€” este projeto Ã© pra vocÃª.

> âš ï¸ **Ã‰ sÃ³ pra o Pi-Star OFICIAL.** Se vocÃª jÃ¡ usa WPSD ou outro fork, nÃ£o
> precisa disto (eles jÃ¡ tÃªm essa funcionalidade). O instalador detecta forks
> e **aborta sem mexer em nada**.

---

## O que ele faz

- **Bandeira do paÃ­s** ao lado do indicativo (derivada do prefixo, tabela de
  prefixos ITU â€” cobertura mundial, ~250 paÃ­ses).
- **Nome do operador** (lido do `DMRIds.dat`, a mesma base que o Pi-Star jÃ¡
  baixa).
- **CÃ³digo do paÃ­s em 3 letras** no lugar do antigo link "(GPS)" â€” que levava
  pro aprs.fi e, na prÃ¡tica, nÃ£o servia pra muita coisa. Ex.: `(BRA)`, `(USA)`.
- **Tooltip com o nome do paÃ­s por extenso**, no idioma do dashboard:
  se o Pi-Star estÃ¡ em inglÃªs mostra "Brazil"; em portuguÃªs, "Brasil".

### Por que Ã© leve (importa no Pi Zero)

- A bandeira Ã© uma **imagem SVG estÃ¡tica** servida pelo prÃ³prio webserver â€”
  custo de CPU praticamente zero.
- O `DMRIds.dat` (~6-7 MB) Ã© lido **uma Ãºnica vez** e os pares
  `indicativo â†’ nome` ficam num cache em disco, refeito sÃ³ quando o arquivo
  muda. Nos refreshes de 1,5 s, lÃª-se o cache â€” nÃ£o o arquivo inteiro.
- O resto (paÃ­s, cÃ³digo, tooltip) Ã© resolvido em memÃ³ria, sem rede.

Ou seja: nada do processamento pesado que torna os forks completos lentos no
Pi Zero.

---

## InstalaÃ§Ã£o

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
- monta o sistema de arquivos como leitura/escrita (`rpi-rw` se existir, senÃ£o
  `mount -o remount,rw /`) e volta pra somente-leitura ao terminar;
- faz **backup** do `lh.php` original antes de qualquer mudanÃ§a;
- aplica o patch de forma **nÃ£o-destrutiva** (injeta sÃ³ o necessÃ¡rio, nÃ£o troca
  o arquivo) e valida a sintaxe com `php -l`;
- instala a **reaplicaÃ§Ã£o automÃ¡tica pÃ³s-update** (veja abaixo).

---

## Sobrevive aos updates do Pi-Star

O dashboard do Pi-Star Ã© um repositÃ³rio git, e um `pistar-update` pode reverter
o `lh.php`. Pra isso nÃ£o derrubar a customizaÃ§Ã£o, o projeto instala um
`cron.hourly` que **reaplica o patch sozinho** caso detecte que ele sumiu.
Quando nÃ£o hÃ¡ nada a fazer, ele sai na hora, sem tocar no disco.

---

## DesinstalaÃ§Ã£o

```bash
sudo ./install.sh --uninstall
```

Restaura o `lh.php` original (do backup), remove as bandeiras, o
`lh_enrich.php`, o script de reaplicaÃ§Ã£o e o cron. **Ctrl+F5** depois.

---

## LimitaÃ§Ãµes (sendo honesto)

- **Nome** vem do `DMRIds.dat` (base DMR). Indicativos que nÃ£o estiverem nessa
  base aparecem sÃ³ com a bandeira â€” sem nome. Nenhum erro, sÃ³ nÃ£o tem o dado.
- **Bandeira/paÃ­s** dependem do prefixo do indicativo (tabela ITU). Cobre a
  grande maioria, mas se aparecer um prefixo nÃ£o mapeado a linha fica sem
  bandeira (sem quebrar nada). PRs de novos prefixos sÃ£o bem-vindos.
- Pensado e testado no **Pi-Star oficial** (v4.2.6, Pi Zero W). NÃ£o testei em
  todas as versÃµes/forks â€” e em forks ele nem instala, de propÃ³sito.

---

## Estrutura

```
pistar-flags-dash/
â”œâ”€â”€ install.sh              # instala / desinstala / status
â”œâ”€â”€ lib/patch-lh.sh         # patch nao-destrutivo e idempotente do lh.php
â”œâ”€â”€ src/
â”‚   â”œâ”€â”€ lh_enrich.php        # funcoes: pais (ITU), nome (DMRIds+cache), tooltip
â”‚   â””â”€â”€ flags/               # bandeiras SVG (ISO-3166-1 alpha-2) + INDEX
â”œâ”€â”€ pistar-lh-reapply        # reaplicacao pos-update
â”œâ”€â”€ cron/zz-pistar-lh-reapply # gatilho cron.hourly
â”œâ”€â”€ README.md
â””â”€â”€ LICENSE                  # GPL-3.0
```

---

## CrÃ©ditos

- **Andy Taylor (MW0MWZ)** â€” criador do Pi-Star, que tornou tudo isso possÃ­vel.
- **Hampus Borgos** â€” [country-flags](https://github.com/hampusborgos/country-flags),
  as bandeiras SVG (domÃ­nio pÃºblico).
- A ideia de bandeira por indicativo Ã© inspirada em forks do Pi-Star (como o
  WPSD/W0CHP) e em projetos como o `xlxd`.

Este **nÃ£o** Ã© um projeto oficial do Pi-Star. Use por sua conta e risco.

## LicenÃ§a

**GPL-3.0** â€” veja [LICENSE](LICENSE). O Pi-Star Ã© GPL; este Ã© um trabalho
derivado e segue a mesma licenÃ§a.
