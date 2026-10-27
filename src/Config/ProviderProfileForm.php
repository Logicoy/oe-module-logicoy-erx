<?php

/**
 * Renders the eRx provider fields into OpenEMR's add-user and edit-user
 * screens, and saves them back.
 *
 * Core is not touched. Both screens dispatch UserEditRenderEvent from inside
 * their <form>, in a full width cell that core explicitly set aside for module
 * content, so fields echoed here post with the rest of the user record. The
 * values land in this module's own table, keyed on the OpenEMR user id.
 *
 * Saving happens on UserCreatedEvent / UserUpdatedEvent, which fire after core
 * has already committed the user. There is nothing left to abort by then, so a
 * value that fails validation is dropped and reported to the module log rather
 * than raised — the markup carries the matching client side constraints so this
 * is the hand edited request case, not the normal one.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Config;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class ProviderProfileForm
{
    /** The user screens are behind Administration, Users. Match that. */
    public const ACL_SECTION = 'admin';
    public const ACL_SUBSECTION = 'users';

    private ProviderProfileStore $store;
    private UserContactStore $contacts;
    private SystemLogger $logger;

    public function __construct(
        ?ProviderProfileStore $store = null,
        ?SystemLogger $logger = null,
        ?UserContactStore $contacts = null
    ) {
        $this->store = $store ?? new ProviderProfileStore();
        $this->logger = $logger ?? new SystemLogger();
        $this->contacts = $contacts ?? new UserContactStore();
    }

    /**
     * @param int|null $userId null on the add-user screen
     */
    public function render(?int $userId): void
    {
        if (!AclMain::aclCheckCore(self::ACL_SECTION, self::ACL_SUBSECTION)) {
            return;
        }

        $profile = $userId !== null ? $this->store->get($userId) : [
            'dob' => null,
            'gender' => null,
            'prescribing_type' => ProviderProfileInput::TYPE_ERX,
            'dea_expiration_date' => null,
            'dea_state' => null,
            'has_ssn_last4' => false,
        ];

        $contact = $userId !== null
            ? $this->contacts->get($userId)
            : array_fill_keys(array_keys(UserContactStore::COLUMNS), '');

        $prefix = ProviderProfileInput::FIELD_PREFIX;
        $epcs = ($profile['prescribing_type'] ?? '') === ProviderProfileInput::TYPE_EPCS;

        // Echoed inline rather than through Twig, for the same reason the
        // demographics card is: this fires mid-page inside core's own markup.
        echo '<table class="border-0" cellpadding="0" cellspacing="0" style="width:600px;">';

        echo '<tr><td colspan="4" class="pt-2">'
            . '<span class="font-weight-bold">' . text(xl('LogiCoy eRx')) . '</span>'
            . '</td></tr>';

        echo '<tr>';
        echo '<td style="width:150px;"><span class="text">' . text(xl('Date of Birth')) . ': </span></td>';
        echo '<td style="width:220px;">'
            . '<input type="date" class="form-control" style="width:150px;" '
            . 'name="' . attr($prefix . 'dob') . '" '
            . 'max="' . attr(date('Y-m-d')) . '" '
            . 'value="' . attr((string)($profile['dob'] ?? '')) . '">'
            . '</td>';
        echo '<td style="width:150px;"><span class="text">'
            . text(xl('eRx Prescribing Type')) . ': </span></td>';
        echo '<td style="width:150px;">'
            . '<select class="form-control" style="width:150px;" name="'
            . attr($prefix . 'prescribing_type') . '">'
            . '<option value="' . attr(ProviderProfileInput::TYPE_ERX) . '"'
            . ($epcs ? '' : ' selected') . '>' . text(xl('eRx')) . '</option>'
            . '<option value="' . attr(ProviderProfileInput::TYPE_EPCS) . '"'
            . ($epcs ? ' selected' : '') . '>' . text(xl('EPCS')) . '</option>'
            . '</select>'
            . '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td><span class="text">' . text(xl('Gender')) . ': </span></td>';
        echo '<td>' . $this->genderField($prefix . 'gender', (string)($profile['gender'] ?? '')) . '</td>';
        echo '<td colspan="2"><span class="text text-muted">'
            . text(xl('Required to register the prescriber. OpenEMR has no user record field for this.'))
            . '</span></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td><span class="text">' . text(xl('DEA Expiration Date')) . ': </span></td>';
        echo '<td>'
            . '<input type="date" class="form-control" style="width:150px;" '
            . 'name="' . attr($prefix . 'dea_expiration_date') . '" '
            . 'value="' . attr((string)($profile['dea_expiration_date'] ?? '')) . '">'
            . '</td>';
        echo '<td><span class="text">' . text(xl('DEA State')) . ': </span></td>';
        echo '<td>' . $this->stateField($prefix . 'dea_state', (string)($profile['dea_state'] ?? '')) . '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td><span class="text">' . text(xl('SSN (last 4)')) . ': </span></td>';
        echo '<td>'
            . '<input type="text" class="form-control" style="width:150px;" '
            . 'name="' . attr($prefix . 'ssn_last4') . '" '
            . 'inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" '
            . 'placeholder="' . attr($profile['has_ssn_last4'] ? ProviderProfileStore::SSN_MASK : '') . '" '
            . 'title="' . attr(xl('Four digits')) . '">'
            . '</td>';
        echo '<td colspan="2"><span class="text text-muted">';
        if ($profile['has_ssn_last4']) {
            echo '<label class="mb-0"><input type="checkbox" value="1" name="'
                . attr($prefix . 'ssn_last4_clear') . '"> '
                . text(xl('Remove stored SSN')) . '</label><br />';
            echo text(xl('On file. Leave blank to keep it.'));
        } else {
            echo text(xl('Required for EPCS only.'));
        }
        echo '</span></td>';
        echo '</tr>';

        // Two calls rather than one concatenated string: xl() needs a literal
        // for the translation extractor to find it.
        // Phone and address live on the core `users` row but neither user
        // screen shows them, so they are surfaced here and written straight
        // back to those columns. See UserContactStore.
        echo '<tr>';
        echo '<td><span class="text">' . text(xl('Phone')) . ': </span></td>';
        echo '<td>' . $this->textField($prefix . 'phone', $contact['phone'], 'tel') . '</td>';
        echo '<td><span class="text">' . text(xl('Street')) . ': </span></td>';
        echo '<td>' . $this->textField($prefix . 'street', $contact['street']) . '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td><span class="text">' . text(xl('City')) . ': </span></td>';
        echo '<td>' . $this->textField($prefix . 'city', $contact['city']) . '</td>';
        echo '<td><span class="text">' . text(xl('State')) . ': </span></td>';
        echo '<td>' . $this->stateField($prefix . 'state', $contact['state']) . '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td><span class="text">' . text(xl('ZIP')) . ': </span></td>';
        echo '<td>' . $this->textField($prefix . 'zip', $contact['zip']) . '</td>';
        echo '<td colspan="2"><span class="text text-muted">'
            . text(xl('These are the Address Book fields for this user.'))
            . ' '
            . text(xl('Registering a prescriber needs them.'))
            . '</span></td>';
        echo '</tr>';

        echo '<tr><td colspan="4"><span class="text text-muted">'
            . text(xl('EPCS also needs the DEA number, date of birth, DEA expiration date, DEA state and SSN last 4.'))
            . ' '
            . text(xl('Registering the prescriber with LogiCoy is refused until they are all present.'))
            . '</span></td></tr>';

        echo '</table>';
    }

    /**
     * Saves what the user form posted. Missing fields mean the form predates
     * this version, so an existing profile is left alone rather than blanked.
     *
     * @param array<string, mixed> $post
     */
    public function saveFromPost(?int $userId, array $post): void
    {
        if ($userId === null || $userId <= 0 || ProviderProfileInput::isAbsent($post)) {
            return;
        }

        $input = new ProviderProfileInput($post);
        $this->store->save($userId, $input->toArray());
        $this->contacts->save($userId, $this->contactValues($post));

        if (!$input->isValid()) {
            // Field names only. The values are PII and never reach a log.
            $this->logger->warning(
                'LogiCoy eRx: ignored invalid provider profile input',
                ['user_id' => $userId, 'problems' => $input->getErrors()]
            );
        }
    }

    /**
     * The contact fields the form actually carried, keyed by form field.
     *
     * A field the form did not send is left out entirely so the core column
     * keeps its value; a field sent blank is the admin clearing it, since these
     * render pre-filled.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function contactValues(array $post): array
    {
        $values = [];
        foreach (array_keys(UserContactStore::COLUMNS) as $field) {
            $key = ProviderProfileInput::FIELD_PREFIX . $field;
            if (array_key_exists($key, $post)) {
                $values[$field] = (string)$post[$key];
            }
        }

        return $values;
    }

    /**
     * The add-user screen has no id yet — UserCreatedEvent hands back the POST
     * it was given, so the username is the only handle on the new row.
     */
    public function resolveUserIdByUsername(?string $username): ?int
    {
        $username = trim((string)($username ?? ''));
        if ($username === '') {
            return null;
        }

        $rows = QueryUtils::fetchRecords('SELECT id FROM users WHERE username = ? LIMIT 1', [$username]);
        $id = (int)($rows[0]['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function textField(string $name, string $current, string $type = 'text'): string
    {
        return '<input type="' . attr($type) . '" class="form-control" style="width:150px;" '
            . 'name="' . attr($name) . '" value="' . attr($current) . '">';
    }

    /**
     * The backend takes M, F or O and nothing else — not OpenEMR's wider sex
     * list, which is why this is a fixed set rather than a list_options lookup.
     */
    private function genderField(string $name, string $current): string
    {
        $labels = ['M' => xl('Male'), 'F' => xl('Female'), 'O' => xl('Other')];

        $html = '<select class="form-control" style="width:150px;" name="' . attr($name) . '">';
        $html .= '<option value=""></option>';
        foreach ($labels as $code => $label) {
            $html .= '<option value="' . attr($code) . '"'
                . ($code === $current ? ' selected' : '') . '>' . text($label) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * Built from the core `state` list so the codes match everywhere else in
     * OpenEMR. A site that has emptied that list gets a plain two letter box.
     */
    private function stateField(string $name, string $current): string
    {
        $states = QueryUtils::fetchRecords(
            "SELECT option_id, title FROM list_options
             WHERE list_id = 'state' AND activity = 1 ORDER BY seq, title",
            []
        );

        if ($states === []) {
            return '<input type="text" class="form-control" style="width:150px;" '
                . 'name="' . attr($name) . '" pattern="[A-Za-z]{2}" maxlength="2" '
                . 'value="' . attr($current) . '">';
        }

        $html = '<select class="form-control" style="width:150px;" name="' . attr($name) . '">';
        $html .= '<option value=""></option>';
        foreach ($states as $state) {
            $code = (string)($state['option_id'] ?? '');
            $html .= '<option value="' . attr($code) . '"'
                . ($code === $current && $current !== '' ? ' selected' : '') . '>'
                . text($code . ' - ' . (string)($state['title'] ?? '')) . '</option>';
        }

        return $html . '</select>';
    }
}
