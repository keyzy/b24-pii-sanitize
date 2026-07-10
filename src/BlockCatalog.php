<?php

declare(strict_types=1);

namespace Keyzy\Pii;

final class BlockCatalog
{
    public const MARKETING_OPERATIONAL_TABLE_PATTERN =
        '/^b_sender_(abuse|call_log|contact($|_)|counter($|_)|file($|_)|group($|_)|list($|_)|mailing($|_)|message($|_)|posting($|_)|queue($|_)|timeline_queue($|_))/';

    /**
     * @return array<string, array{title:string, description:string, default:bool, operations:list<array<string, mixed>>}>
     */
    public static function all(string $chatMode, string $nonAdminLoginMode = 'anonymize'): array
    {
        if (!in_array($nonAdminLoginMode, ['anonymize', 'keep'], true)) {
            throw new \InvalidArgumentException('Режим логинов не-администраторов должен быть anonymize или keep.');
        }
        $requiresFiles = ['requires_block' => 'files_disk'];
        $userSet = [
            'EMAIL' => self::runEmail(),
            'NAME' => self::concatId('Пользователь '),
            'XML_ID' => self::runExternalId(),
        ];
        if ($nonAdminLoginMode === 'anonymize') {
            $userSet = ['LOGIN' => self::runLoginExceptAdministrators()] + $userSet;
        }

        return [
            'crm_core' => [
                'title' => 'CRM: контакты и компании',
                'description' => 'Технические имена вместо ФИО/названий, очистка адресов, комментариев, фото и коммуникаций.',
                'default' => true,
                'operations' => [
                    self::collect('b_crm_contact', ['PHOTO'], [], 10) + $requiresFiles,
                    self::collect('b_crm_company', ['LOGO'], [], 10) + $requiresFiles,
                    self::update(
                        'b_crm_contact',
                        ['NAME' => self::concatId('Контакт '), 'FULL_NAME' => self::concatId('Контакт ')],
                        [
                            'HONORIFIC', 'SECOND_NAME', 'LAST_NAME', 'POST', 'BIRTHDATE', 'COMMENTS', 'PHOTO',
                            'ADDRESS', 'ADDRESS_2', 'ADDRESS_CITY', 'ADDRESS_POSTAL_CODE', 'ADDRESS_REGION',
                            'ADDRESS_PROVINCE', 'ADDRESS_COUNTRY', 'ADDRESS_COUNTRY_CODE', 'ORIGIN_ID',
                            'SEARCH_CONTENT', 'SOURCE_DESCRIPTION',
                        ],
                        ['/^(PHONE|EMAIL|WEB|IM)$/i'],
                        ['HAS_PHONE' => self::literal('N'), 'HAS_EMAIL' => self::literal('N'), 'HAS_IMOL' => self::literal('N')],
                    ),
                    self::update(
                        'b_crm_company',
                        ['TITLE' => self::concatId('Компания ')],
                        [
                            'LOGO', 'BANKING_DETAILS', 'COMMENTS', 'ADDRESS', 'ADDRESS_2', 'ADDRESS_CITY',
                            'ADDRESS_POSTAL_CODE', 'ADDRESS_REGION', 'ADDRESS_PROVINCE', 'ADDRESS_COUNTRY',
                            'ADDRESS_COUNTRY_CODE', 'ADDRESS_LEGAL', 'REG_ADDRESS', 'ORIGIN_ID', 'SEARCH_CONTENT',
                        ],
                        ['/^(PHONE|EMAIL|WEB|IM)$/i', '/^(ADDRESS|REG_ADDRESS)/i'],
                        ['HAS_PHONE' => self::literal('N'), 'HAS_EMAIL' => self::literal('N'), 'HAS_IMOL' => self::literal('N')],
                    ),
                    self::delete('b_crm_field_multi', [
                        self::whereIn('TYPE_ID', ['PHONE', 'EMAIL', 'WEB', 'IM', 'LINK']),
                    ], 30),
                ],
            ],

            'crm_requisites' => [
                'title' => 'CRM: реквизиты, банки и адреса',
                'description' => 'Строки и связи сохраняются; идентификаторы, банковские и адресные поля зануляются.',
                'default' => true,
                'operations' => [
                    self::update(
                        'b_crm_requisite',
                        ['NAME' => self::concatId('Реквизит '), 'XML_ID' => self::concatId('pii_requisite_')],
                        ['COMMENTS', 'ORIGIN_ID'],
                        ['/^RQ_/i'],
                    ),
                    self::update(
                        'b_crm_bank_detail',
                        ['NAME' => self::concatId('Банковские данные '), 'XML_ID' => self::concatId('pii_bank_')],
                        ['COMMENTS', 'ORIGIN_ID'],
                        ['/^RQ_/i', '/(BANK|BIK|ACCOUNT|IBAN|SWIFT)/i'],
                    ),
                    self::update('b_crm_requisite_addr', [], [], ['/(ADDRESS|CITY|POSTAL|REGION|PROVINCE|COUNTRY|LOC_ADDR)/i']),
                    self::update('b_crm_addr', [], [], ['/(ADDRESS|CITY|POSTAL|REGION|PROVINCE|COUNTRY|LOC_ADDR)/i']),
                    self::update('b_crm_entity_addr', [], [], ['/(ADDRESS|CITY|POSTAL|REGION|PROVINCE|COUNTRY|LOC_ADDR)/i']),
                    self::special('sanitize_crm_location_addresses', 21),
                ],
            ],

            'crm_entities' => [
                'title' => 'CRM: лиды, сделки, счета и смарт-процессы',
                'description' => 'Сущности и ID остаются, заголовки становятся техническими, свободный текст и прямые ПДн очищаются.',
                'default' => true,
                'operations' => [
                    self::collect('b_crm_lead', ['PHOTO'], [], 10) + $requiresFiles,
                    self::update(
                        'b_crm_lead',
                        ['TITLE' => self::concatId('Лид '), 'NAME' => self::concatId('Лид '), 'FULL_NAME' => self::concatId('Лид ')],
                        [
                            'HONORIFIC', 'SECOND_NAME', 'LAST_NAME', 'POST', 'BIRTHDATE', 'COMMENTS', 'PHOTO',
                            'COMPANY_TITLE', 'SOURCE_DESCRIPTION', 'ADDRESS', 'ADDRESS_2', 'ADDRESS_CITY',
                            'ADDRESS_POSTAL_CODE', 'ADDRESS_REGION', 'ADDRESS_PROVINCE', 'ADDRESS_COUNTRY',
                            'ADDRESS_COUNTRY_CODE', 'ORIGIN_ID', 'SEARCH_CONTENT',
                        ],
                        ['/(PHONE|EMAIL|WEB|IM)$/i'],
                        ['HAS_PHONE' => self::literal('N'), 'HAS_EMAIL' => self::literal('N'), 'HAS_IMOL' => self::literal('N')],
                    ),
                    self::update(
                        'b_crm_deal',
                        ['TITLE' => self::concatId('Сделка ')],
                        ['COMMENTS', 'ADDITIONAL_INFO', 'SOURCE_DESCRIPTION', 'ORIGIN_ID', 'SEARCH_CONTENT'],
                    ),
                    self::update(
                        'b_crm_quote',
                        ['TITLE' => self::concatId('Предложение ')],
                        ['COMMENTS', 'CONTENT', 'TERMS', 'CLIENT_TITLE', 'CLIENT_ADDR', 'CLIENT_CONTACT', 'CLIENT_EMAIL', 'CLIENT_PHONE', 'CLIENT_TP_ID', 'SEARCH_CONTENT'],
                        ['/(ADDRESS|PHONE|EMAIL|CONTACT|CLIENT_)/i'],
                    ),
                    self::update(
                        'b_crm_invoice',
                        ['ORDER_TOPIC' => self::concatId('Счет '), 'TITLE' => self::concatId('Счет ')],
                        ['COMMENTS', 'USER_DESCRIPTION', 'RESPONSIBLE_EMAIL', 'SEARCH_CONTENT'],
                        ['/(ADDRESS|PHONE|EMAIL|CONTACT|CLIENT_)/i'],
                    ),
                    self::updatePattern(
                        '/^b_crm_dynamic_items_[0-9]+$/',
                        ['TITLE' => self::concatId('Элемент '), 'XML_ID' => self::concatId('pii_item_')],
                        ['COMMENTS', 'DESCRIPTION', 'SEARCH_CONTENT'],
                        ['/(PHONE|EMAIL|ADDRESS|COMMENT|DESCRIPTION|BIRTH|PASSPORT)/i'],
                        ['/^UF_/i'],
                    ),
                    self::updatePattern(
                        '/^b_crm_(invoice|quote|order).*props.*value/i',
                        [],
                        ['VALUE'],
                        ['/(PHONE|EMAIL|ADDRESS|COMMENT|DESCRIPTION|CONTACT|CLIENT)/i'],
                        ['/(_ID|^ID$)/i'],
                    ),
                    self::updatePattern(
                        '/^b_crm_order_/',
                        [],
                        [],
                        ['/(PHONE|EMAIL|ADDRESS|COMMENT|DESCRIPTION|CONTACT|CLIENT|MESSAGE|TEXT)/i'],
                        ['/(_ID|^ID$)/i'],
                    ),
                ],
            ],

            'crm_financials' => [
                'title' => 'Суммы CRM-элементов',
                'description' => 'Опционально: суммы, налоги и цены товарных строк становятся равны нулю; количества, валюты, ID и связи сохраняются.',
                'default' => false,
                'operations' => [
                    self::zero('b_crm_lead', ['OPPORTUNITY', 'TAX_VALUE', 'OPPORTUNITY_ACCOUNT', 'TAX_VALUE_ACCOUNT']),
                    self::zero('b_crm_deal', ['OPPORTUNITY', 'TAX_VALUE', 'OPPORTUNITY_ACCOUNT', 'TAX_VALUE_ACCOUNT']),
                    self::zero('b_crm_quote', ['OPPORTUNITY', 'TAX_VALUE', 'OPPORTUNITY_ACCOUNT', 'TAX_VALUE_ACCOUNT']),
                    self::zero('b_crm_invoice', [
                        'PRICE_DELIVERY', 'PRICE_PAYMENT', 'PRICE', 'DISCOUNT_VALUE', 'TAX_VALUE', 'SUM_PAID', 'PS_SUM',
                    ]),
                    self::zeroPattern('/^b_crm_dynamic_items_[0-9]+$/', [
                        'OPPORTUNITY', 'TAX_VALUE', 'OPPORTUNITY_ACCOUNT', 'TAX_VALUE_ACCOUNT',
                    ]),
                    self::zero('b_crm_product_row', [
                        'PRICE', 'PRICE_ACCOUNT', 'PRICE_EXCLUSIVE', 'PRICE_NETTO', 'PRICE_BRUTTO',
                        'DISCOUNT_SUM', 'TAX_SUM',
                    ]),
                    self::zero('b_crm_act', ['RESULT_SUM']),
                    self::zeroPattern(
                        '/^b_crm_.*(sum_stat|inv_stat|invoice_stat|channel_stat|act_stat)$/',
                        [],
                        ['/(^|_)(SUM|PRICE|OPPORTUNITY|REVENUE|TAX_VALUE|OWED|PAID)(_|$)/i'],
                        ['/(^|_)(QTY|QUANTITY|COUNT)(_|$)/i', '/(_ID|^ID$)/i', '/CURRENCY/i'],
                        22,
                    ),
                ],
            ],

            'catalog_prices' => [
                'title' => 'Цены товаров',
                'description' => 'Опционально: основные, масштабированные и закупочные цены товаров становятся равны нулю; товары и остатки сохраняются.',
                'default' => false,
                'operations' => [
                    self::zero('b_catalog_price', ['PRICE', 'PRICE_SCALE']),
                    self::zero('b_catalog_product', ['PURCHASING_PRICE']),
                    self::zero('b_crm_product', ['PRICE']),
                    self::zero('b_catalog_docs_element', ['PURCHASING_PRICE', 'BASE_PRICE', 'BASE_PRICE_EXTRA']),
                    self::zero('b_catalog_store_batch', ['PURCHASING_PRICE']),
                    self::zero('b_catalog_store_batch_docs_element', ['BATCH_PRICE']),
                    self::zero('b_sale_viewed_product', ['PRICE']),
                ],
            ],

            'crm_history' => [
                'title' => 'CRM: активности, таймлайн и история',
                'description' => 'Строки и связи сохраняются; содержимое активностей, таймлайна и старые значения обезличиваются.',
                'default' => true,
                'operations' => [
                    self::collect('b_crm_act_elem', ['ELEMENT_ID'], [self::whereEq('STORAGE_TYPE_ID', 1)], 10) + $requiresFiles,
                    self::update(
                        'b_crm_act',
                        ['SUBJECT' => self::concatId('Активность ')],
                        ['DESCRIPTION', 'LOCATION', 'ORIGIN_ID', 'AUTHOR_NAME', 'SEARCH_CONTENT'],
                        ['/(PHONE|EMAIL|ADDRESS|RECIPIENT|FROM|TO|BODY|MESSAGE|COMMENT)/i'],
                    ),
                    self::special('sanitize_crm_activity_structures', 21),
                    self::delete('b_crm_act_comm', [], 30),
                    self::delete('b_crm_act_elem', [], 30),
                    self::deletePattern('/^b_crm_tracking_(trace($|_)|pool($|_)|phone_number($|_))/', 31),
                    self::delete('b_crm_exclusion', [], 31),
                    self::delete('b_crm_act_channel_stat', [], 31),
                    self::delete('b_crm_entity_channel', [], 31),
                    self::delete('b_crm_ai_queue', [], 31),
                    self::update('b_crm_usr_mt', [], ['EMAIL_FROM', 'SUBJECT', 'BODY', 'TITLE'], ['/(EMAIL|PHONE|MESSAGE|TEXT|CONTENT|DESCRIPTION)/i']),
                    self::update(
                        'b_crm_event',
                        ['EVENT_NAME' => self::concatId('Событие ')],
                        ['EVENT_TEXT_1', 'EVENT_TEXT_2', 'FILES'],
                        [],
                        [],
                        [],
                        [],
                        22,
                    ),
                    self::update(
                        'b_crm_timeline',
                        ['SOURCE_ID' => self::concatId('pii_timeline_')],
                        ['COMMENT'],
                        [],
                        [],
                        [],
                        [],
                        22,
                    ),
                    self::update('b_crm_timeline_note', [], ['TEXT'], [], [], [], [], 22),
                    self::special('sanitize_crm_timeline_structures', 23),
                    self::updatePattern(
                        '/^b_crm_livefeed($|_)/',
                        [],
                        [],
                        ['/(TITLE|MESSAGE|TEXT|COMMENT|CONTENT|DESCRIPTION)/i'],
                        ['/(_ID|^ID$)/i'],
                        [],
                        [],
                        22,
                    ),
                    self::delete('b_crm_timeline_search', [], 30),
                ],
            ],

            'mail' => [
                'title' => 'Почта и CRM email',
                'description' => 'Полное удаление писем, адресатов, вложений, UID, почтовых контактов и email-активностей CRM.',
                'default' => true,
                'operations' => [
                    self::collectPattern('/^b_mail_.*(attachment|attach).*$/i', ['FILE_ID'], ['/(^|_)FILE_ID$/i'], 10) + $requiresFiles,
                    self::update(
                        'b_mail_mailbox',
                        [],
                        ['NAME', 'LOGIN', 'USERNAME', 'PASSWORD', 'SERVER', 'EMAIL', 'OPTIONS', 'TOKEN'],
                        ['/(LOGIN|USER|PASS|TOKEN|SECRET|EMAIL|SERVER|OPTIONS)/i'],
                        ['ACTIVE' => self::literal('N')],
                    ),
                    self::special('crm_email_activities', 30),
                    self::delete('b_mail_blacklist', [], 30),
                    self::delete('b_mail_user_signature', [], 30),
                    self::deletePattern('/^b_mail_(message_.+|msg_attachment|attachment|log|contact($|_))/', 31),
                    self::delete('b_mail_message', [], 32),
                ],
            ],

            'chats' => [
                'title' => 'Чаты, открытые линии и коннекторы',
                'description' => $chatMode === 'delete'
                    ? 'Удаляются сообщения, служебные хвосты, отношения и сами чаты.'
                    : 'Удаляются сообщения и служебные хвосты; чаты, связи и пользователи сохраняются с техническими названиями.',
                'default' => true,
                'operations' => array_values(array_filter([
                    self::collect('b_im_chat', ['AVATAR'], [], 10) + $requiresFiles,
                    self::collectPattern('/^b_im_message_param$/', ['FILE_ID'], ['/(^|_)FILE_ID$/i'], 10) + $requiresFiles,
                    self::update('b_im_chat', ['TITLE' => self::concatId('Чат ')], ['DESCRIPTION', 'AVATAR'], ['/(NAME|DESCRIPTION|AVATAR|^ENTITY_DATA_)/i']),
                    self::update(
                        'b_im_chat',
                        ['ENTITY_ID' => self::concatId('pii_chat_')],
                        ['CALL_NUMBER'],
                        [],
                        [],
                        [],
                        [self::whereIn('ENTITY_TYPE', ['CALL', 'LINES', 'MAIL'])],
                    ),
                    self::deletePattern('/^b_im_(message_param|message_index|message_favorite|message_uuid)/', 30),
                    self::deletePattern('/^b_im_(chat_index|link_url($|_))/', 30),
                    self::delete('b_im_message', [], 31),
                    self::deletePattern('/^b_im_(recent($|_)|counter($|_)|call($|_))/', 32),
                    self::deletePattern('/^b_imopenlines_(session($|_)|tracker($|_)|chat($|_)|message($|_)|rating($|_)|vote($|_)|livechat($|_)|log($|_))/', 32),
                    self::delete('b_imopenlines_user_relation', [], 32),
                    self::deletePattern('/^b_imconnector_(message|chat|user|profile|log)($|_)/', 32),
                    $chatMode === 'delete' ? self::delete('b_im_relation', [], 33) : null,
                    $chatMode === 'delete' ? self::delete('b_im_chat', [], 34) : null,
                ])),
            ],

            'users' => [
                'title' => 'Пользователи портала',
                'description' => $nonAdminLoginMode === 'anonymize'
                    ? 'ID, пароли и логины администраторов сохраняются; логины остальных и все email становятся техническими, профиль и фото очищаются.'
                    : 'ID, пароли и все логины сохраняются; email становятся техническими, профиль и фото очищаются.',
                'default' => true,
                'operations' => [
                    self::collect('b_user', ['PERSONAL_PHOTO', 'WORK_LOGO'], [], 10) + $requiresFiles,
                    self::update(
                        'b_user',
                        $userSet,
                        ['SECOND_NAME', 'LAST_NAME', 'TITLE', 'PERSONAL_GENDER', 'PERSONAL_BIRTHDAY', 'NOTES', 'ADMIN_NOTES'],
                        ['/^PERSONAL_/i', '/^WORK_/i', '/(PHONE|MOBILE|ADDRESS|CITY|ZIP|STATE|COUNTRY|(^|_)IP$)/i'],
                        [],
                        ['/^(PASSWORD|CHECKWORD|CONFIRM_CODE)$/i'],
                    ),
                    self::delete('b_user_phone_auth', [], 30),
                    self::delete('b_user_index', [], 30),
                    self::delete('b_user_option', [], 30),
                ],
            ],

            'tasks_social_calendar' => [
                'title' => 'Задачи (без удаления)',
                'description' => 'Задачи остаются, заголовки заменяются на технические, описания и комментарии зануляются.',
                'default' => true,
                'operations' => [
                    self::update('b_tasks', ['TITLE' => self::concatId('Задача ')], ['DESCRIPTION', 'DECLINE_REASON', 'MARK'], ['/(COMMENT|MESSAGE|TEXT|ADDRESS|LOCATION)/i']),
                    self::update('b_tasks_template', ['TITLE' => self::concatId('Шаблон задачи ')], ['DESCRIPTION'], ['/(COMMENT|MESSAGE|TEXT)/i']),
                    self::update('b_tasks_checklist_items', ['TITLE' => self::concatId('Пункт ')], [], ['/(COMMENT|TEXT|DESCRIPTION)/i']),
                    self::update('b_tasks_checklist', ['TITLE' => self::concatId('Пункт ')], [], ['/(COMMENT|TEXT|DESCRIPTION)/i']),
                    self::updatePattern('/^b_tasks_(result|elapsed_time|member|tag|task_tag)$/', [], [], ['/(COMMENT|MESSAGE|TEXT|NAME|TITLE|DESCRIPTION)/i'], ['/(_ID|^ID$)/i']),
                    self::update('b_forum_topic', ['TITLE' => self::concatId('Тема ')], ['DESCRIPTION', 'TITLE_SEO'], ['/(COMMENT|DESCRIPTION|TEXT|MESSAGE)/i']),
                    self::update('b_forum_message', [], ['POST_MESSAGE', 'POST_MESSAGE_FILTER', 'AUTHOR_NAME', 'AUTHOR_EMAIL', 'SERVICE_DATA'], ['/(COMMENT|DESCRIPTION|TEXT|MESSAGE|EMAIL|PHONE)/i']),
                    self::delete('b_tasks_search_index', [], 30),
                    self::delete('b_tasks_log', [], 30),
                    self::delete('b_tasks_scorer_event', [], 30),
                ],
            ],

            'social_calendar' => [
                'title' => 'Календарь, лента и процессы',
                'description' => 'Элементы остаются, названия заменяются на технические, свободный текст зануляется.',
                'default' => true,
                'operations' => [
                    self::update(
                        'b_calendar_event',
                        ['NAME' => self::concatId('Событие '), 'DAV_XML_ID' => self::concatId('pii_event_')],
                        ['DESCRIPTION', 'LOCATION', 'REMIND', 'MEETING', 'SEARCHABLE_CONTENT', 'RRULE'],
                        ['/(COMMENT|MESSAGE|TEXT|ADDRESS)/i']
                    ),
                    self::update('b_calendar_section', ['NAME' => self::concatId('Календарь '), 'XML_ID' => self::concatId('pii_calendar_')], ['DESCRIPTION'], ['/(COMMENT|TEXT)/i']),
                    self::deletePattern('/^b_calendar_(event_connection|section_connection|sharing_link)($|_)/', 30),
                    self::update('b_sonet_log', ['TITLE' => self::concatId('Запись ')], ['MESSAGE', 'TEXT_MESSAGE', 'URL'], ['/(COMMENT|DESCRIPTION|TEXT|MESSAGE)/i']),
                    self::update('b_sonet_log_comment', [], ['MESSAGE', 'TEXT_MESSAGE'], ['/(COMMENT|DESCRIPTION|TEXT|MESSAGE)/i']),
                    self::update('b_sonet_messages', [], ['MESSAGE', 'TITLE'], ['/(COMMENT|DESCRIPTION|TEXT|MESSAGE)/i']),
                    self::update('b_blog_post', ['TITLE' => self::concatId('Публикация ')], ['DETAIL_TEXT', 'PREVIEW_TEXT', 'MICRO'], ['/(COMMENT|DESCRIPTION|TEXT|MESSAGE)/i']),
                    self::update('b_blog_comment', [], ['POST_TEXT'], ['/(COMMENT|DESCRIPTION|TEXT|MESSAGE)/i']),
                    self::delete('b_sonet_log_index', [], 30),
                    self::special('sanitize_process_iblocks', 25),
                ],
            ],

            'marketing' => [
                'title' => 'Маркетинг: рассылки и сегменты',
                'description' => 'Опционально: удаляются сегменты, кампании, сообщения, получатели, очереди и статистика; роли и системные настройки сохраняются.',
                'default' => false,
                'operations' => [
                    self::collect('b_sender_file', ['FILE_ID'], [], 10) + $requiresFiles,
                    self::collect('b_sender_mailing_attachment', ['FILE_ID'], [], 10) + $requiresFiles,
                    self::deletePattern(
                        self::MARKETING_OPERATIONAL_TABLE_PATTERN,
                        30,
                    ),
                ],
            ],

            'forms_marketing_sale' => [
                'title' => 'Формы, подписки и продажи',
                'description' => 'Удаляются ответы форм и подписчики; в сохраненных заказах очищаются свойства и свободный текст.',
                'default' => true,
                'operations' => [
                    self::collect('b_catalog_store', ['IMAGE_ID'], [], 10) + $requiresFiles,
                    self::update(
                        'b_catalog_store',
                        ['TITLE' => self::concatId('Склад '), 'XML_ID' => self::concatId('pii_store_')],
                        ['ADDRESS', 'DESCRIPTION', 'GPS_N', 'GPS_S', 'IMAGE_ID', 'PHONE', 'SCHEDULE', 'EMAIL', 'CODE'],
                    ),
                    self::special('sanitize_iblock_embedded_contacts', 25),
                    self::delete('b_form_result_answer', [], 30),
                    self::delete('b_form_result', [], 31),
                    self::delete('b_subscription_rubric', [], 30),
                    self::delete('b_subscription', [], 31),
                    self::delete('b_posting_recipient', [], 30),
                    self::update('b_posting', ['SUBJECT' => self::concatId('Рассылка ')], ['BODY'], ['/(EMAIL|PHONE|MESSAGE|TEXT)/i']),
                    self::update('b_sale_order', [], ['USER_DESCRIPTION', 'COMMENTS'], ['/(PHONE|EMAIL|ADDRESS|COMMENT|DESCRIPTION)/i'], [], ['/(_ID|^ID$)/i']),
                    self::update('b_sale_order_props_value', [], ['VALUE'], ['/(PHONE|EMAIL|ADDRESS|COMMENT|DESCRIPTION)/i'], [], ['/(_ID|^ID$)/i']),
                    self::update('b_sale_user_props_value', [], ['VALUE'], ['/(PHONE|EMAIL|ADDRESS|COMMENT|DESCRIPTION)/i'], [], ['/(_ID|^ID$)/i']),
                    self::update('b_sale_basket', [], ['DETAIL_PAGE_URL'], ['/(PHONE|EMAIL|ADDRESS|COMMENT|DESCRIPTION)/i'], [], ['/(_ID|^ID$)/i']),
                    self::delete('b_sale_order_archive', [], 32),
                    self::deletePattern('/^b_sale_order_change($|_)/', 32),
                ],
            ],

            'telephony' => [
                'title' => 'Телефония и записи звонков',
                'description' => 'Очищаются номера, внешние идентификаторы, комментарии и ссылки на записи; расшифровки удаляются.',
                'default' => true,
                'operations' => [
                    self::collectPattern('/^b_voximplant_/', ['FILE_ID', 'RECORD_FILE_ID'], ['/(^|_)(FILE|RECORD_FILE)_ID$/i'], 10) + $requiresFiles,
                    self::updatePattern(
                        '/^b_voximplant_/',
                        [],
                        [
                            'CALLER_ID', 'PHONE_NUMBER', 'PORTAL_NUMBER', 'INCOMING_PHONE', 'PHONE', 'NUMBER',
                        ],
                        ['/(PHONE|NUMBER|CALLER|CALLED|TRANSCRIPT|COMMENT|DESCRIPTION|CALL_LOG|RECORD_(URL|LINK)|EXTERNAL_CALL_ID)/i'],
                        ['/(_USER_ID|^USER_ID$|^ID$)/i'],
                    ),
                    self::deletePattern('/^b_voximplant_.*(transcript|record_content).*$/i', 32),
                ],
            ],

            'custom_fields' => [
                'title' => 'Пользовательские поля CRM и пользователей',
                'description' => 'Собирается только метаинформация UF; очевидные ПДн предлагаются к очистке, неоднозначные поля требуют решения.',
                'default' => true,
                'operations' => [
                    self::special('user_fields', 15),
                ],
            ],

            'files_disk' => [
                'title' => 'Файлы, вложения и Диск',
                'description' => 'Формируется манифест b_file.ID/путей, затем удаляются выбранные физические файлы; метаданные Диска обезличиваются.',
                'default' => true,
                'operations' => [
                    self::collect('b_file', ['ID'], [], 9) + ['runtime_modes' => ['standalone-mysql']],
                    self::collect('b_disk_version', ['FILE_ID'], [], 10),
                    self::collect('b_disk_object', ['FILE_ID'], [], 10),
                    self::collect('b_landing_file', ['FILE_ID'], [], 10),
                    self::update('b_disk_object', ['NAME' => self::concatId('Объект диска ')], ['CODE', 'FILE_ID'], ['/(DESCRIPTION|COMMENT|EXTERNAL_LINK)/i']),
                    self::update('b_disk_storage', ['NAME' => self::concatId('Хранилище ')], [], ['/(DESCRIPTION|COMMENT)/i']),
                    self::delete('b_disk_attached_object', [], 30),
                    self::delete('b_disk_external_link', [], 30),
                    self::delete('b_disk_object_extended_index', [], 30),
                    self::delete('b_disk_object_head_index', [], 30),
                    self::delete('b_landing_file', [], 30),
                    self::delete('b_disk_version', [], 31),
                    self::special('delete_files', 40),
                ],
            ],

            'indexes_logs_bp' => [
                'title' => 'Индексы, корзина, логи и история бизнес-процессов',
                'description' => 'Удаляются поисковые копии старых значений, duplicate-index, recycle bin, журналы и runtime-история БП.',
                'default' => true,
                'operations' => [
                    self::deletePattern('/^b_search_content_/', 50),
                    self::delete('b_search_content', [], 51),
                    self::deletePattern('/^b_search_(tags($|_)|stem($|_)|phrase($|_)|suggest($|_)|user_right($|_))/', 51),
                    self::deletePattern('/^b_crm_(dp_|recycling_)/', 50),
                    self::deletePattern('/^b_crm_(act_fastsearch|company_index|contact_index|deal_index|lead_index|dynamic_items_[0-9]+_index)$/', 50),
                    self::deletePattern('/^b_(im_chat_index|im_link_url($|_)|sonet_log_index|tasks_search_index|voximplant_statistic_index)$/', 50),
                    self::deletePattern('/^b_recyclebin($|_)/', 50),
                    self::delete('b_entity_usage', [], 50),
                    self::delete('b_ai_history', [], 50),
                    self::deletePattern('/^b_documentgenerator_(document|external_link)$/', 50),
                    self::delete('b_dav_connections', [], 50),
                    self::deletePattern('/^b_timeman_(report|reports)/', 50),
                    self::deletePattern('/^b_conv_/', 50),
                    self::deletePattern('/^b_urlpreview_/', 50),
                    self::delete('b_short_uri', [], 50),
                    self::update('b_landing_block', [], ['CONTENT', 'SEARCH_CONTENT'], ['/(EMAIL|PHONE|ADDRESS|MESSAGE|TEXT|DESCRIPTION)/i']),
                    self::deletePattern('/^b_landing_(history|copilot_generations|copilot_requests)$/', 50),
                    self::deletePattern('/^b_bp_(workflow_state|workflow_instance|tracking|history|task($|_))/', 50),
                    self::update('b_bp_rest_activity', [], ['PROPERTIES'], ['/(EMAIL|PHONE|TOKEN|SECRET|PASSWORD|MESSAGE|TEXT|DESCRIPTION)/i']),
                    self::delete('b_event_log', [], 50),
                    self::delete('b_log', [], 50),
                ],
            ],

            'secrets_queues' => [
                'title' => 'Сессии, токены, внешние интеграции и очереди',
                'description' => 'Для безопасной копии удаляются активные сессии, OAuth/REST-токены, очереди отправки и секреты интеграций.',
                'default' => true,
                'operations' => [
                    self::update('b_user', [], ['CHECKWORD', 'CONFIRM_CODE', 'BX_USER_ID'], [], []),
                    self::deletePattern('/^b_(session|user_hit_auth|user_phone_auth|user_appl_pass|sec_session)($|_)/', 50),
                    self::deletePattern('/^b_oauth_.*(token|code|scope).*$/i', 50),
                    self::updatePattern('/^b_oauth_client$/', [], ['CLIENT_SECRET'], ['/(TOKEN|SECRET|PASSWORD)/i'], [], [], ['ACTIVE' => self::literal('N')]),
                    self::deletePattern('/^b_rest_(ap|app|event|event_offline|log|usage)($|_)/', 50),
                    self::deletePattern('/^b_socialservices_(user|contact)($|_)/', 50),
                    self::delete('b_bitrixcloud_option', [], 50),
                    self::deletePattern('/^b_pull_/', 50),
                    self::delete('b_event_attachment', [], 50),
                    self::delete('b_event', [], 51),
                    self::updatePattern('/^b_imconnector_status$/', [], [], ['/(TOKEN|SECRET|PASSWORD|DATA|CONNECTION|REGISTER)/i'], [], [], ['ACTIVE' => self::literal('N')]),
                    self::special('sanitize_options', 52),
                    self::special('clear_cache', 80),
                ],
            ],

            'verification' => [
                'title' => 'Проверка остаточных ПДн',
                'description' => 'После apply считает остаточные email/телефоны и проверяет ключевые инварианты без сохранения найденных значений.',
                'default' => true,
                'operations' => [
                    self::special('verify', 90),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function update(
        string $table,
        array $set = [],
        array $empty = [],
        array $columnPatterns = [],
        array $additionalSet = [],
        array $excludePatterns = [],
        array $where = [],
        int $stage = 20,
    ): array {
        return [
            'type' => 'update',
            'table' => $table,
            'stage' => $stage,
            'set' => $set + $additionalSet,
            'empty' => $empty,
            'column_patterns' => $columnPatterns,
            'exclude_patterns' => $excludePatterns,
            'where' => $where,
        ];
    }

    /** @return array<string, mixed> */
    private static function updatePattern(
        string $tablePattern,
        array $set = [],
        array $empty = [],
        array $columnPatterns = [],
        array $excludePatterns = [],
        array $where = [],
        array $additionalSet = [],
        int $stage = 20,
    ): array {
        $operation = self::update('', $set, $empty, $columnPatterns, $additionalSet, $excludePatterns, $where, $stage);
        unset($operation['table']);
        $operation['table_pattern'] = $tablePattern;
        return $operation;
    }

    /** @return array<string, mixed> */
    private static function zero(
        string $table,
        array $columns = [],
        array $columnPatterns = [],
        array $excludePatterns = [],
        int $stage = 20,
    ): array {
        $operation = self::update($table, [], $columns, $columnPatterns, [], $excludePatterns, [], $stage);
        $operation['column_specification'] = self::literal(0);
        $operation['allowed_data_types'] = [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'decimal', 'numeric', 'float', 'double', 'real', 'bit',
        ];
        return $operation;
    }

    /** @return array<string, mixed> */
    private static function zeroPattern(
        string $tablePattern,
        array $columns = [],
        array $columnPatterns = [],
        array $excludePatterns = [],
        int $stage = 20,
    ): array {
        $operation = self::zero('', $columns, $columnPatterns, $excludePatterns, $stage);
        unset($operation['table']);
        $operation['table_pattern'] = $tablePattern;
        return $operation;
    }

    /** @return array<string, mixed> */
    private static function delete(string $table, array $where = [], int $stage = 30): array
    {
        return ['type' => 'delete', 'table' => $table, 'where' => $where, 'stage' => $stage];
    }

    /** @return array<string, mixed> */
    private static function deletePattern(string $tablePattern, int $stage): array
    {
        return ['type' => 'delete', 'table_pattern' => $tablePattern, 'where' => [], 'stage' => $stage];
    }

    /** @return array<string, mixed> */
    private static function collect(string $table, array $columns, array $where, int $stage): array
    {
        return [
            'type' => 'collect_files',
            'table' => $table,
            'columns' => $columns,
            'column_patterns' => [],
            'where' => $where,
            'stage' => $stage,
        ];
    }

    /** @return array<string, mixed> */
    private static function collectPattern(string $tablePattern, array $columns, array $columnPatterns, int $stage): array
    {
        return [
            'type' => 'collect_files',
            'table_pattern' => $tablePattern,
            'columns' => $columns,
            'column_patterns' => $columnPatterns,
            'where' => [],
            'stage' => $stage,
        ];
    }

    /** @return array<string, mixed> */
    private static function special(string $name, int $stage): array
    {
        return ['type' => 'special', 'name' => $name, 'stage' => $stage];
    }

    /** @return array{mode:string,prefix:string} */
    private static function concatId(string $prefix): array
    {
        return ['mode' => 'concat_id', 'prefix' => $prefix];
    }

    /** @return array{mode:string,value:mixed} */
    private static function literal(mixed $value): array
    {
        return ['mode' => 'literal', 'value' => $value];
    }

    /** @return array{mode:string} */
    private static function runLoginExceptAdministrators(): array
    {
        return ['mode' => 'run_login_except_administrators'];
    }

    /** @return array{mode:string} */
    private static function runEmail(): array
    {
        return ['mode' => 'run_email'];
    }

    /** @return array{mode:string} */
    private static function runExternalId(): array
    {
        return ['mode' => 'run_external_id'];
    }

    /** @return array{column:string,op:string,values:list<mixed>} */
    private static function whereIn(string $column, array $values): array
    {
        return ['column' => $column, 'op' => 'in', 'values' => array_values($values)];
    }

    /** @return array{column:string,op:string,value:mixed} */
    private static function whereEq(string $column, mixed $value): array
    {
        return ['column' => $column, 'op' => 'eq', 'value' => $value];
    }
}
