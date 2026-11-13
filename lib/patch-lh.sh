#!/bin/bash
#
# patch-lh.sh - Aplica (idempotente) o patch de bandeira + nome do operador
# no lh.php do dashboard Pi-Star OFICIAL, SEM substituir o arquivo: injeta
# apenas as linhas necessarias no arquivo que ja esta instalado.
#
# Uso:  patch-lh.sh <caminho-do-lh.php>
# Saida:  0 = patch aplicado (ou ja estava aplicado)
#         2 = estrutura nao reconhecida (provavel fork; nada alterado)
#         3 = erro de argumento / arquivo inexistente
#
# Estrategia: usa 'perl' (presente no Pi-Star) para substituicoes ancoradas
# em trechos estaveis do lh.php oficial. Cada passo so age se ainda nao
# tiver sido aplicado, portanto rodar varias vezes e seguro.
#
# Parte do projeto pistar-flags-dash. GPL-3.0.

set -u

LH="${1:-}"
MARK="lh_flag_img"   # presenca => ja patcheado

if [ -z "$LH" ] || [ ! -f "$LH" ]; then
    echo "patch-lh: arquivo lh.php invalido: '$LH'" >&2
    exit 3
fi

# Ja patcheado? idempotente -> sucesso sem fazer nada.
if grep -q "$MARK" "$LH"; then
    exit 0
fi

# --- Verificacao de compatibilidade (Pi-Star oficial) ---
# Ancoras (strings literais) que precisam existir no lh.php oficial. Se
# faltarem, provavelmente e um fork com estrutura diferente: abortar.
need_patterns=(
    "/config/language.php'"
    '$ber'
    '$listElem[2] > 9999'
    'float:left;'
)
for p in "${need_patterns[@]}"; do
    if ! grep -qF -- "$p" "$LH"; then
        echo "patch-lh: ancora ausente ('$p'). lh.php nao reconhecido como Pi-Star oficial; abortando." >&2
        exit 2
    fi
done

# Trabalhar numa copia temporaria; so substitui o original no fim.
TMP="$(mktemp)"
cp "$LH" "$TMP"

perl -0777 -i -pe '
    # 1) include do lh_enrich.php logo apos o include do language.php
    s{(include_once \$_SERVER\[.DOCUMENT_ROOT.\].\x27/config/language\.php\x27;[^\n]*\n)}
     {$1include_once \$_SERVER[\x27DOCUMENT_ROOT\x27].\x27/mmdvmhost/lh_enrich.php\x27;      // pistar-flags-dash (added)\n}s;

    # 1b) injeta o <style> do tooltip ISO-3 antes do titulo da lista (uma vez).
    s{(<b><\?php echo \$lang\[\x27last_heard_list\x27\];\?></b>)}
     {<style>/* pistar-flags-dash */
.lh-iso3\{position:relative;opacity:0.7;font-family:monospace;cursor:help;\}
.lh-iso3 .lh-tip\{display:none;position:absolute;bottom:135%;left:50%;transform:translateX(-50%);
  background:#222;color:#fff;padding:2px 7px;border-radius:4px;white-space:nowrap;
  font-family:sans-serif;font-size:0.85em;opacity:1;z-index:50;pointer-events:none;
  box-shadow:0 1px 4px rgba(0,0,0,0.4);\}
.lh-iso3 .lh-tip:after\{content:"";position:absolute;top:100%;left:50%;transform:translateX(-50%);
  border:5px solid transparent;border-top-color:#222;\}
.lh-iso3:hover .lh-tip\{display:block;\}
.footer::after\{content:"Flags, names & country codes: pistar-flags-dash by PP5RD (github.com/danielregis81/pistar-flags-dash)";display:block;margin-top:6px;opacity:0.85;\}
</style>
$1}s;

    # 1c) estreita a coluna "Time": width minimo + nowrap + respiro a direita no <th>.
    s{<th>(<a class="tooltip" href="\#"><\?php echo \$lang\[\x27time\x27\];)}
     {<th style="width:1%;white-space:nowrap;padding-right:10px;">$1}s;

    # 1d) nowrap + respiro a direita na celula do time.
    s{echo "<td align=\\"left\\">\$local_time</td>";}
     {echo "<td align=\\"left\\" style=\\"white-space:nowrap;padding-right:10px;\\">\$local_time</td>";}g;

    # 2) calculo de $lhFlag/$lhNameSpan logo apos a linha que define $ber = ...;
    s{(\$ber\s*=\s*htmlspecialchars\([^\n]*\n)}
     {$1
        // --- pistar-flags-dash (added): bandeira + nome do operador ---
        \$lhFlag = lh_flag_img((string)\$listElem[2]);
        \$lhName = lh_operator_name((string)\$listElem[2]);
        \$lhNameSpan = (\$lhName !== \x27\x27)
            ? \x27 <span class="lh-opname" style="opacity:0.85;font-size:0.9em;">\x27 . \$lhName . \x27</span>\x27
            : \x27\x27;
        \$lhIso3 = lh_iso3((string)\$listElem[2]);
        \$lhCountry = lh_country_name((string)\$listElem[2], isset(\$pistarLanguage) ? \$pistarLanguage : \x27english_uk\x27);
        \$lhIso3Span = (\$lhIso3 !== \x27\x27)
            ? \x27<span class="lh-iso3">(\x27 . \$lhIso3
              . ((\$lhCountry !== \x27\x27) ? \x27<span class="lh-tip">\x27 . htmlspecialchars(\$lhCountry, ENT_QUOTES, \x27UTF-8\x27) . \x27</span>\x27 : \x27\x27)
              . \x27)</span>\x27
            : \x27\x27;
        // --- fim pistar-flags-dash ---
}s;

    # 3a) branch DMR ID numerico com link (> 9999)
    s{(echo "<td align=\\"left\\"><a href=\\"".\$idLookupUrl.\$csUrl."\\" target=\\"_blank\\">)(\$cs)(</a></td>";)}
     {echo "<td align=\\"left\\">\$lhFlag<a href=\\"".\$idLookupUrl.\$csUrl."\\" target=\\"_blank\\">$2</a>\$lhNameSpan</td>";}g;

    # 3b) branch DMR ID numerico sem link (<= 9999)
    s{(else \{ echo "<td align=\\"left\\">)(\$cs)(</td>"; \})}
     {else { echo "<td align=\\"left\\">\$lhFlag$2\$lhNameSpan</td>"; }}g;

    # 3c) branches D-Star / alfanumerico (com e sem sufixo): injeta flag apos
    #     o <div float:left;> e nome antes do fechamento desse div.
    s{(<div style=\\"float:left;\\">)(<a href=\\"".\$callsignLookupUrl.\$csTrimUrl."\\" target=\\"_blank\\">\$csTrimHtml</a>)(/\$csSuffix)?(</div>)}
     {$1\$lhFlag$2${3}\$lhNameSpan$4}g;

    # 3d) substitui o bloco inutil "(GPS)" -> aprs.fi pelo codigo ISO-3 do pais.
    #     Casamento tolerante: do &#40; ate &#41; que envolve o link do aprs.fi.
    #     Usa [^<]* / .*? para absorver as partes variaveis (href, csTrimUrl).
    s{&\#40;<a\s+href=\\"https://aprs\.fi/.*?>GPS</a>&\#41;}
     {\$lhIso3Span}gs;
' "$TMP"

# Validar: patch de fato entrou?
if ! grep -q "$MARK" "$TMP"; then
    echo "patch-lh: substituicoes nao surtiram efeito (estrutura divergente); abortando sem alterar." >&2
    rm -f "$TMP"
    exit 2
fi

# Validar sintaxe PHP se o binario existir
if command -v php >/dev/null 2>&1; then
    if ! php -l "$TMP" >/dev/null 2>&1; then
        echo "patch-lh: php -l falhou no resultado; abortando sem alterar." >&2
        php -l "$TMP" >&2 || true
        rm -f "$TMP"
        exit 2
    fi
fi

# Preservar permissoes do original e substituir
chmod --reference="$LH" "$TMP" 2>/dev/null || chmod 0755 "$TMP"
chown --reference="$LH" "$TMP" 2>/dev/null || true
cat "$TMP" > "$LH"
rm -f "$TMP"
exit 0
