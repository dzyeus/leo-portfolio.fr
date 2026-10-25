<?php
/**
 * Formulaire de partie (création et modification) au style du panel admin.
 *
 * $v : valeurs des champs (title, event_date, event_time, location, price,
 *      description, scenario, equipment_required, rules)
 */
function admin_event_form_fields(array $v): void
{
    $val = fn(string $k) => e((string)($v[$k] ?? ''));
    ?>
    <section class="a-card">
        <div class="a-card-head"><h2><?= ui_icon('info', 18) ?> Informations</h2></div>
        <div class="s-field">
            <label for="title">Titre de la partie <span class="s-req">*</span></label>
            <input type="text" id="title" name="title" required maxlength="150" value="<?= $val('title') ?>" placeholder="Ex. : Opération Rideau de Fer">
        </div>
        <div class="s-form-row">
            <div class="s-field">
                <label for="event_date">Date <span class="s-req">*</span></label>
                <input type="date" id="event_date" name="event_date" required value="<?= $val('event_date') ?>">
            </div>
            <div class="s-field">
                <label for="event_time">Heure <span class="s-req">*</span></label>
                <input type="time" id="event_time" name="event_time" required value="<?= $val('event_time') ?>">
            </div>
        </div>
        <div class="s-form-row">
            <div class="s-field">
                <label for="location">Lieu <span class="s-req">*</span></label>
                <input type="text" id="location" name="location" required maxlength="200" value="<?= $val('location') ?>" placeholder="Ex. : Forêt de Randan, ou 45.241880, 3.303910">
                <span class="s-hint">Nom du terrain ou coordonnées GPS : un bouton « Itinéraire » est ajouté automatiquement.</span>
            </div>
            <div class="s-field">
                <label for="price">Prix par place (€) <span class="s-req">*</span></label>
                <input type="text" id="price" name="price" required inputmode="decimal" value="<?= $val('price') ?>">
                <span class="s-hint">0 = partie gratuite (inscription sans paiement).</span>
            </div>
        </div>
    </section>

    <section class="a-card">
        <div class="a-card-head"><h2><?= ui_icon('target', 18) ?> Présentation et règles</h2></div>
        <div class="s-field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3" placeholder="2 ou 3 phrases : ce qui attend les joueurs."><?= $val('description') ?></textarea>
            <span class="s-hint">Affichée sur l'agenda, l'accueil et dans l'email d'annonce.</span>
        </div>
        <div class="s-field">
            <label for="scenario">Scénario détaillé</label>
            <textarea id="scenario" name="scenario" rows="6" placeholder="Contexte, objectifs de chaque camp…"><?= $val('scenario') ?></textarea>
        </div>
        <div class="s-field">
            <label for="equipment_required">Matériel recommandé</label>
            <textarea id="equipment_required" name="equipment_required" rows="3" placeholder="Ex. : lunettes de protection obligatoires, tenue adaptée…"><?= $val('equipment_required') ?></textarea>
        </div>
        <div class="s-field" style="margin-bottom:0;">
            <label for="rules">Règles spéciales</label>
            <textarea id="rules" name="rules" rows="3" placeholder="Ex. : 1 médic par équipe, retour en safe zone par les chemins…"><?= $val('rules') ?></textarea>
        </div>
    </section>
    <?php
}

/** Prix en centimes → « 10,00 » pour le champ du formulaire. */
function admin_price_input(int $cents): string
{
    return number_format($cents / 100, 2, ',', '');
}
