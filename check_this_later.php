<?
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
GLOBAL $USER;
if (!$USER->IsAdmin()) {
    die();
}

use \Bitrix\Main\Loader;
use Bitrix\Main\PhoneNumber\Format;
use Bitrix\Main\PhoneNumber\Parser;
use Bitrix\Main\UserTable;
use Bitrix\Highloadblock\HighloadBlockTable as HLBT;
use Bitrix\Main\Localization\Loc,
    Bitrix\Main\ORM\Data\DataManager,
    Bitrix\Main\ORM\Fields\IntegerField,
    Bitrix\Main\ORM\Fields\StringField,
    Bitrix\Main\ORM\Fields\Validators\LengthValidator;

class UfPhonesTable extends DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'new_users_uf_phones';
    }

    /**
     * Returns entity map definition.
     *
     * @return array
     */
    public static function getMap()
    {
        return [
            new IntegerField(
                'ID',
                [
                    'primary' => true,
                    'required' => true,
                    'title' => Loc::getMessage('UF_PHONES_ENTITY_ID_FIELD')
                ]
            ),
            new StringField(
                'VALUE',
                [
                    'required' => true,
                    'validation' => [__CLASS__, 'validateValue'],
                    'title' => Loc::getMessage('UF_PHONES_ENTITY_VALUE_FIELD')
                ]
            ),
        ];
    }

    /**
     * Returns validators for VALUE field.
     *
     * @return array
     */
    public static function validateValue()
    {
        return [
            new LengthValidator(null, 255),
        ];
    }
}

Loader::includeModule('highloadblock');

const
MY_HL_BLOCK_ID = 6,
HIGHLOAD_USERS = [3, 4];

$sites = [
    4 => 'newsitelinz',
    3 => 'sitelinz'
];

$arHighBlock = [];

function userGetter($highloadId)
{
    $entityDataClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($highloadId)->getDataClass();

    $result = $entityDataClass::getList([
        "select" => array("*"),
    ]);

    while ($user = $result->fetch()) {
        $phone = str_replace([' ', '&nbsp;'], '', $user['UF_PHONE']);
        $parsePhone = Parser::getInstance()->parse($phone)->format(Format::E164);
        $user['UF_PHONE'] = str_replace('+', '', $parsePhone);

//        unset($user['UF_LOGIN'], $user['UF_LAST_NAME'], $user['UF_NAME'], $user['UF_SECOND_NAME']);
        $arUsers[] = $user;

    }

    return $arUsers ?? [];
}

function generateEmailAddress()
{
    return substr(bin2hex(random_bytes(10)), 0, 10) . '@sitelinz-mail.ru';
}

/** @var \Bitrix\Main\Entity\DataManager $commonUsersHl */
$commonUsersHl = HLBT::compileEntity(MY_HL_BLOCK_ID)->getDataClass();

if ($_GET['system_email_users'] == 'Y') {
    foreach (HIGHLOAD_USERS as $hlId) {

        /** @var \Bitrix\Main\Entity\DataManager $userTable */
        $userTable = HLBT::compileEntity($hlId)->getDataClass();
        $rsUsers = $userTable::query()
            ->whereIn('UF_EMAIL', ['sitelinz@mail.ru', 'Sitelinz@mail.ru', 'Sitelinz@mai.ru'])
            ->setSelect(['*'])
            ->exec();

        $arGroupedUsers = [];
        while ($user = $rsUsers->fetch()) {
            if (!empty($user['UF_PHONE'])) {
                $arGroupedUsers[$user['UF_PHONE']][] = $user;
            } else {
            }
        }


        continue;

        $arUsers = $rsUsers->fetchAll();
        echo '<pre>';
        print_r(count($arUsers));
        echo '</pre>';
        $arPhones = array_column($arUsers, 'UF_PHONE');

        /** @var \Bitrix\Main\Entity\Query $query */
        $query = \UfPhonesTable::query();

        $rsNewUsers = $query
            ->registerRuntimeField(new \Bitrix\Main\Entity\ReferenceField(
                'NEW_USER',
                $commonUsersHl::getEntity(),
                \Bitrix\Main\Entity\Query\Join::on('this.ID', 'ref.ID')
            ))
            ->where(
                \Bitrix\Main\Entity\Query::filter()
                    ->logic('or')
                    ->whereIn('VALUE', $arPhones)
                    ->whereIn('NEW_USER.UF_PHONE', $arPhones)
            )
            ->setSelect([
                'ID',
                'VALUE',
                'PHONE' => 'NEW_USER.UF_PHONE',
                'EMAIL' => 'NEW_USER.UF_EMAIL'
            ])
            ->exec();

    }

    die;
}

if ($_GET['delete_users'] == 'Y') {
    $rsUsers = UserTable::query()
        ->where('TIMESTAMP_X', '>=', (new \Bitrix\Main\Type\DateTime())->setDate(2021, 3, 29))
        ->setSelect(['ID'])
        ->exec();

    while ($userRow = $rsUsers->fetch()) {
        \CUser::Delete($userRow['ID']);
    }
    die;
}

if ($_GET['delete_hl_users'] == 'Y' and !empty($commonUsersHl)) {
    \Bitrix\Main\Application::getConnection()->truncateTable($commonUsersHl::getTableName());
    die;
}

if ($_GET['collect_users'] == 'Y') {

    foreach (HIGHLOAD_USERS as $highloadId) {
        $arUsersHL = userGetter($highloadId);
        foreach ($arUsersHL as $user) {
            if (in_array($user['UF_EMAIL'], ['sitelinz@mail.ru', 'Sitelinz@mail.ru', 'Sitelinz@mai.ru'])) {
                $user['UF_EMAIL'] = (empty($user['UF_PHONE']))
                    ? generateEmailAddress()
                    : "{$user['UF_PHONE']}@sitelinz-mail.ru";
            }

            if ($highloadId == 4) {
                // ид с первого сайта
                $user['UF_USER_ID_SITE_1'][] = $user['UF_USER_ID'];
            } else {
                // ид со второго сайта
                $user['UF_USER_ID_SITE_2'][] = $user['UF_USER_ID'];
            }


            $user['SITE'] = $sites[$highloadId];

            $arHighBlock[$user['UF_EMAIL']][] = $user;
        }
    }

    $arEmails = [];
    $userObj = new \CUser;

    $arGroupedUsers = $arPhoneEmailUsers = $arNewUsers = [];

    // группируем пользователей по емейлу
    // паралельно группируем их по номеру телефона
    foreach ($arHighBlock as $email => $arUsers) {

        // если существует 2 и более пользователей с одним емейлом
        if (count($arUsers) >= 2) {
            $oneUser = current($arUsers);

            // собираем доп номера
            foreach ($arUsers as $user) {
                if ($user['SITE'] == $sites[4]) {
                    $oneUser['UF_USER_ID_SITE_1'][] = $user['UF_USER_ID_SITE_1'][0];
                } else {
                    $oneUser['UF_USER_ID_SITE_2'][] = $user['UF_USER_ID_SITE_2'][0];
                }

                if (in_array($user['UF_PHONE'], $oneUser['UF_PHONES'] ?? [])) {
                    continue;
                }

                $oneUser['UF_PHONES'][] = $user['UF_PHONE'];
            }

            if (!empty($oneUser)) {
                // если у пользователя есть доп телефоны, обрезаем дубли
                if (!empty($oneUser['UF_PHONES'])) {
                    $oneUser['UF_PHONES'] = array_unique($oneUser['UF_PHONES']);
                }

                $arGroupedUsers[$email] = $oneUser;

                // если у пользователя нет основного телефона, но есть доп телефоны
                if (empty($arGroupedUsers[$email]['UF_PHONE']) and !empty($arGroupedUsers[$email]['UF_PHONES'])) {
                    // кладем доп телефон в основном и удаляем его
                    $arGroupedUsers[$email]['UF_PHONE'] = $arGroupedUsers[$email]['UF_PHONES'][0];
                    unset($arGroupedUsers[$email]['UF_PHONES'][0]);
                }
            }
        } else {
            $arGroupedUsers[$email] = $arUsers[0];
        }

        if (!empty($arGroupedUsers[$email])) {

            // собираем емейлы и доп телефоны ползователей по их основному номеру
            $mainPhone = $arGroupedUsers[$email]['UF_PHONE'];
            // если у пользователя нет основного телефона, берем из доп номеров
            if (empty($mainPhone) and !empty($arGroupedUsers[$email]['UF_PHONES'])) {
                $mainPhone = current($arGroupedUsers[$email]['UF_PHONES']);
            }

            if (!empty($mainPhone)) {
                $arPhoneEmailUsers[$mainPhone][] = [
                    'EMAIL' => $arGroupedUsers[$email]['UF_EMAIL'],
                    'PHONE' => $mainPhone,
                    'EXTRA_PHONES' => $arGroupedUsers[$email]['UF_PHONES'] ?? [],
                    'UF_USER_ID_SITE_1' => $arGroupedUsers[$email]['UF_USER_ID_SITE_1'] ?? [],
                    'UF_USER_ID_SITE_2' => $arGroupedUsers[$email]['UF_USER_ID_SITE_2'] ?? [],
                    'SITE' => $arGroupedUsers[$email]['SITE']
                ];
            }
        }
    }
    $arSkipUsersEmail = [];

    // на данном этапе имеем пользователей сгруппированных по емейлу, так же нужно провести группироку по номерам телефона
    if (!empty($arGroupedUsers) and !empty($arPhoneEmailUsers)) {
        foreach ($arGroupedUsers as $email => $arUser) {

            if (in_array($email, $arSkipUsersEmail)) {
                continue;
            }

            $mainPhone = $arUser['UF_PHONE'];
            // если у пользователя нет основного телефона, берем из доп номеров
            if (empty($mainPhone) and !empty($arUser['UF_PHONES'])) {
                $mainPhone = current($arGroupedUsers[$email]['UF_PHONES']);
            }

            //у пользователя нет телефона вообще, и нет емейла
            if (empty($mainPhone) and empty($arUser['UF_EMAIL'])) {
                continue;
            }

            // на 1 номер телефона существует 2 и более пользователей=
            if (!empty($arPhoneEmailUsers[$mainPhone]) and count($arPhoneEmailUsers[$mainPhone]) >= 2) {
                $arUser['UF_EMAILS'] = [];

                foreach ($arPhoneEmailUsers[$mainPhone] as $commonPhoneUsers) {

                    if ($commonPhoneUsers['SITE'] == $sites[4]) {
                        foreach ($commonPhoneUsers['UF_USER_ID_SITE_1'] as $extraId) {
                            $arUser['UF_USER_ID_SITE_1'][] = $extraId;
                        }
                    } else {
                        foreach ($commonPhoneUsers['UF_USER_ID_SITE_2'] as $extraId) {
                            $arUser['UF_USER_ID_SITE_2'][] = $extraId;
                        }
                    }

                    if (in_array($commonPhoneUsers['EMAIL'], $arUser['UF_EMAILS'])) {
                        continue;
                    }

                    if ($arUser['UF_EMAIL'] == $commonPhoneUsers['EMAIL']) {
                        $arSkipUsersEmail[] = $commonPhoneUsers['EMAIL'];
                    } else {
                        $arUser['UF_EMAILS'][] = $arSkipUsersEmail[] = $commonPhoneUsers['EMAIL'];
                        $arUser['UF_PHONES'] = array_merge($arUser['UF_PHONES'], $commonPhoneUsers['EXTRA_PHONES']);
                        $arUser['UF_USER_ID_SITE_1'] = array_merge($arUser['UF_USER_ID_SITE_1'], $commonPhoneUsers['UF_USER_ID_SITE_1']);
                        $arUser['UF_USER_ID_SITE_2'] = array_merge($arUser['UF_USER_ID_SITE_2'], $commonPhoneUsers['UF_USER_ID_SITE_2']);
                    }
                }
            }

            $firstExtraPhone = current($arUser['UF_PHONES']);

            // на случай, если у пользователя не задан основной телефон, но есть доп телефоны
            if (empty($arUser['UF_PHONE']) and !empty($firstExtraPhone)) {
                $arUser['UF_PHONE'] = $firstExtraPhone;
                unset($arUser['UF_PHONES'][key($arUser['UF_PHONES'])]);
            } elseif (!empty($firstExtraPhone) and $firstExtraPhone == $arUser['UF_PHONE']) {
                unset($arUser['UF_PHONES'][key($arUser['UF_PHONES'])]);
            }

            $arUser['UF_USER_ID_SITE_1'] = array_unique($arUser['UF_USER_ID_SITE_1']);
            $arUser['UF_USER_ID_SITE_2'] = array_unique($arUser['UF_USER_ID_SITE_2']);

            $arNewUsers[] = $arUser;
        }
    }

    // итого: имеем уникальных пользователей с доп номерами и емейлами
    if (!empty($arNewUsers)) {

        foreach ($arNewUsers as $newUser) {
            unset($newUser['ID'], $newUser['UF_USER_ID'], $newUser['SITE']);
            // чистим дубли телефонов
            if (!empty($newUser['UF_PHONES'])) {
                foreach ($newUser['UF_PHONES'] as $index => $phone) {
                    if ((!empty($newUser['UF_PHONE']) and $newUser['UF_PHONE'] == $phone) or empty($phone)) {
                        unset($newUser['UF_PHONES'][$index]);
                    }
                }
            }
            $resAdd = $commonUsersHl::add($newUser);
            if (!$resAdd->isSuccess()) {
                echo '<pre>';
                print_r($resAdd->getErrorMessages());
                echo '</pre>';
                die;
            }
        }
    }
}




/*foreach ($arHighBlock as $user) {
    $entity_data_class::add([
        'UF_NAME' => $user['UF_NAME'],
        'UF_LAST_NAME' => $user['UF_LAST_NAME'],
        'UF_SECOND_NAME' => $user['UF_SECOND_NAME'],
        'UF_LOGIN' => $user['UF_LOGIN'],
        'UF_PASS' => $user['UF_PASS'],
        'UF_CHECK' => $user['UF_CHECK'],
        'UF_EMAIL' => $user['UF_EMAIL'],
        'UF_ACTIVE' => $user['UF_PHONE'],
        'UF_PHONE' => $user['UF_PHONE'],
        'UF_SITE1' => $user['UF_SITE1'],
        'UF_SITE2' => $user['UF_SITE2'],
        'UF_PHONES' => $user['UF_PHONES'],

    ]);
}*/

/*foreach ($arHighBlock as $arUsers) {
    $site = $arUsers['SITE'];

    foreach ($arUsers['USERS'] as $arUser) {
        $userId = 0;
        $userEmail = $arUser['UF_EMAIL'];

        if (empty($arEmails[$userEmail])) {
            $userId = $userObj->Add([
                'NAME' => $arUser['UF_NAME'],
                'LAST_NAME' => $arUser['UF_LAST_NAME'],
                'SECOND_NAME' => $arUser['UF_SECOND_NAME'],
                'LOGIN' => $arUser['UF_LOGIN'],
                'PASSWORD' => $arUser['UF_PASS'],
                'CHECKWORD' => $arUser['UF_CHECK'],
                'EMAIL' => $arUser['UF_EMAIL'],
                'ACTIVE' => $arUser['UF_ACTIVE'],
                'PERSONAL_PHONE' => $arUser['UF_PHONE'],
                'UF_SITE1' => $arUser['UF_USER_ID'] . ';' . $site,
            ]);

            if (empty($userId)) {
                echo '<pre>';
                print_r($userObj->LAST_ERROR);
                echo '</pre>';
                die;
            }

            $arEmails[$userEmail] = [
                'ID' => $userId,
                'SITE' => $site,
            ];

        } else {
            $userObj->Update($arEmails[$userEmail]['ID'], [
                'UF_SITE2' => $arUser['UF_USER_ID'] . ';' . $arEmails[$userEmail]['SITE'],
            ]);
        }
    }
}*/


