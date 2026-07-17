<?php

/*
|--------------------------------------------------------------------------
| Public marketing content — Español (Spanish)
|--------------------------------------------------------------------------
|
| Machine-translated from lang/en/marketing.php, same structure/keys/slugs.
| Regenerate with the i18n reassembly script; edit English in
| config/marketing.php + lang/en/marketing.php, not here.
|
*/

return array (
  'nav' => array(
    'contractors' => 'Contratistas',
    'homeowners' => 'Propietarios',
    'faq' => 'Preguntas frecuentes',
    'sign_in' => 'Iniciar sesión',
    'get_started' => 'Empezar',
    'menu' => 'Menú',
    'language' => 'Idioma',
    'pages' => array(
      'finances' => 'Finanzas',
      'estimates' => 'Presupuestos y documentos',
      'clients' => 'Prospectos y clientes',
      'vendors' => 'Proveedores y cumplimiento',
      'planning' => 'Planificación',
      'team' => 'Equipo y tiempo',
      'communication' => 'Comunicación',
      'automation' => 'Automatización e IA',
    ),
  ),
  'areas' => array(
    'finances' => array(
      'label' => 'Finanzas',
      'eyebrow' => 'Finanzas y contabilidad',
      'grid_heading' => 'Todo en el kit de herramientas financieras',
      'cards' => array(
        'expenses' => array(
          'icon' => 'credit-card',
          'title' => 'Gastos',
          'body' => 'Registra cada costo por proyecto y categoría con los recibos adjuntos.',
          'hero' => 'Controla cada costo de obra—hasta el recibo',
          'lead' => 'Registra costos en el proyecto y la categoría correctos en segundos, adjunta el recibo y observa cómo el costo real de la obra se construye solo mientras gastas.',
          'rows' => array(
            0 => array(
              'heading' => 'Cada costo en su lugar',
              'text' => 'Registra un gasto en el momento en que ocurre y asígnalo a la obra y categoría a la que pertenece. Sin cajas llenas de recibos ni carreras de fin de mes para recordar de qué era un cargo.',
              'points' => array(
                0 => 'Asigna costos a un proyecto y categoría',
                1 => 'Adjunta una foto o recibo en PDF a cada uno',
                2 => 'Divide un solo cargo entre varias obras',
                3 => 'Busca y filtra por obra, proveedor o fecha',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Gastos recientes · 123 Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'credit-card',
                    'label' => 'Home Depot · Madera',
                    'sub' => '$842.10 · Materiales',
                  ),
                  1 => array(
                    'icon' => 'credit-card',
                    'label' => 'Ferguson · Griferías',
                    'sub' => '$1,260.00 · Plomería',
                  ),
                  2 => array(
                    'icon' => 'credit-card',
                    'label' => 'Combustible · Camión 2',
                    'sub' => '$88.40 · Vehículo',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Costos que alimentan tus números',
              'text' => 'Cada gasto pasa directamente al costeo de obra y a tus informes, para que el margen esté siempre al día. Nunca tienes que reintroducir el mismo número dos veces.',
              'points' => array(
                0 => 'Alimenta el costeo de obra automáticamente',
                1 => 'Se refleja en pérdidas y ganancias en tiempo real',
                2 => 'Se concilia con las transacciones bancarias',
                3 => 'Mantiene un registro limpio y listo para auditoría',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Cuando cada costo se etiqueta en el momento de gastar, tu ganancia en cada obra está a la vista—sin conciliar hojas de cálculo a medianoche.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'folder',
              'title' => 'Por proyecto',
              'body' => 'Vincula cada costo a la obra a la que pertenece.',
            ),
            1 => array(
              'icon' => 'tag',
              'title' => 'Por categoría',
              'body' => 'Categorías consistentes mantienen los informes limpios.',
            ),
            2 => array(
              'icon' => 'paper-clip',
              'title' => 'Recibos adjuntos',
              'body' => 'Una foto o PDF en cada gasto.',
            ),
            3 => array(
              'icon' => 'arrows-pointing-out',
              'title' => 'Dividir costos',
              'body' => 'Reparte un cargo entre varias obras.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Alimenta el costeo de obra',
              'body' => 'El margen se actualiza mientras gastas.',
            ),
            5 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Con búsqueda',
              'body' => 'Encuentra cualquier costo en segundos.',
            ),
          ),
          'cta' => array(
            'heading' => 'Conoce tu costo real en cada obra.',
            'sub' => 'Etiqueta cada gasto una vez y deja que tus márgenes se mantengan al día solos.',
          ),
        ),
        'auto-receipts' => array(
          'icon' => 'document-magnifying-glass',
          'title' => 'Recibos automáticos',
          'body' => 'Los recibos enviados por correo o fotografiados se leen, se detallan y se archivan automáticamente.',
          'hero' => 'Recibos que se archivan solos',
          'lead' => 'Reenvía un recibo por correo o toma una foto y Hive lee el proveedor, el total y las líneas, luego lo archiva en la obra correcta—sin escribir nada.',
          'rows' => array(
            0 => array(
              'heading' => 'Fotografíalo o reenvíalo',
              'text' => 'Envía una foto por mensaje, reenvía un correo del proveedor o deja que las cuentas de tienda ingresen los recibos. Nuestra IA extrae el proveedor, la fecha, el total y cada línea por ti.',
              'points' => array(
                0 => 'Fotografía recibos en papel desde el campo',
                1 => 'Reenvía recibos por correo a tu bandeja de Hive',
                2 => 'Detallados hasta cada línea de producto',
                3 => 'Proveedor y totales leídos automáticamente',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Procesado · Home Depot',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-magnifying-glass',
                    'label' => 'Poste 2x4 · cant. 40',
                    'sub' => '$3.18 c/u · $127.20',
                  ),
                  1 => array(
                    'icon' => 'document-magnifying-glass',
                    'label' => 'Tornillos para terraza 5 lb',
                    'sub' => '$42.97',
                  ),
                  2 => array(
                    'icon' => 'document-magnifying-glass',
                    'label' => 'Total leído',
                    'sub' => '$170.17',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Archivado antes de olvidarlo',
              'text' => 'Cada recibo entra como un gasto en el proyecto correcto, listo para conciliar con tu feed bancario. La pila en tu panel desaparece para siempre.',
              'points' => array(
                0 => 'Se convierte en un gasto en la obra correcta',
                1 => 'Listo para conciliar con transacciones bancarias',
                2 => 'Sin captura manual de datos',
                3 => 'Cada línea guardada para garantías y disputas',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Los recibos que solías perder ahora son buscables, detallados y ligados a la obra—sin tocar el teclado.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'camera',
              'title' => 'Captura por foto',
              'body' => 'Fotografía un recibo en papel desde el campo.',
            ),
            1 => array(
              'icon' => 'envelope',
              'title' => 'Envío por correo',
              'body' => 'Reenvía correos de proveedores directamente.',
            ),
            2 => array(
              'icon' => 'list-bullet',
              'title' => 'Detallado',
              'body' => 'Cada línea de producto extraída.',
            ),
            3 => array(
              'icon' => 'sparkles',
              'title' => 'Lectura con IA',
              'body' => 'Proveedor y totales detectados por ti.',
            ),
            4 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Listo para conciliar',
              'body' => 'Coincide con tu feed bancario.',
            ),
            5 => array(
              'icon' => 'folder',
              'title' => 'Archivado automático',
              'body' => 'Aterriza en el proyecto correcto.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja de teclear recibos.',
            'sub' => 'Reenvía o toma una foto y deja que Hive lo detalle y archive por ti.',
          ),
        ),
        'payments' => array(
          'icon' => 'banknotes',
          'title' => 'Pagos',
          'body' => 'Registra lo que pagas y lo que te deben, entre proveedores y clientes.',
          'hero' => 'Ingresos y egresos en un solo lugar',
          'lead' => 'Controla cada pago que haces y cada dólar que te deben, ligados a la obra y el contacto correctos—para saber siempre cómo estás.',
          'rows' => array(
            0 => array(
              'heading' => 'Un libro claro para cada obra',
              'text' => 'Registra los pagos de clientes y los desembolsos a proveedores según ocurren. Cada uno se conecta a un proyecto, un contacto y tus libros, para que nada se escape.',
              'points' => array(
                0 => 'Controla pagos entrantes y salientes',
                1 => 'Liga cada pago a una obra y un contacto',
                2 => 'Ve los saldos pendientes de un vistazo',
                3 => 'Registros que coinciden con tu feed bancario',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Libro del proyecto · Maple St',
                'rows' => array(
                  0 => array(
                    'label' => 'Cliente pagó',
                    'value' => '$31,200',
                  ),
                  1 => array(
                    'label' => 'Pagado a proveedores',
                    'value' => '$18,940',
                  ),
                  2 => array(
                    'label' => 'Pendiente',
                    'value' => '$16,800',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Nunca pierdas de vista quién debe qué',
              'text' => 'Ve qué te deben aún los clientes y qué debes a subcontratistas y proveedores, todo agrupado por obra. Da seguimiento con confianza en vez de adivinar.',
              'points' => array(
                0 => 'Sabe cuánto te deben los clientes',
                1 => 'Sabe cuánto debes a proveedores',
                2 => 'Agrupa saldos por proyecto',
                3 => 'Anticípate al flujo de caja',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Cuando cada pago se registra en un trabajo, tu posición de caja nunca es un misterio a fin de mes.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrow-down-circle',
              'title' => 'Pagos entrantes',
              'body' => 'Registra lo que te pagan los clientes.',
            ),
            1 => array(
              'icon' => 'arrow-up-circle',
              'title' => 'Pagos salientes',
              'body' => 'Registra pagos a proveedores y subcontratistas.',
            ),
            2 => array(
              'icon' => 'folder',
              'title' => 'Por trabajo',
              'body' => 'Cada pago vinculado a un proyecto.',
            ),
            3 => array(
              'icon' => 'scale',
              'title' => 'Saldos',
              'body' => 'Ve los importes pendientes con claridad.',
            ),
            4 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Conciliado con el banco',
              'body' => 'Coincide con tus transacciones.',
            ),
            5 => array(
              'icon' => 'chart-bar',
              'title' => 'Claridad de caja',
              'body' => 'Siempre sabes dónde estás.',
            ),
          ),
          'cta' => array(
            'heading' => 'Siempre sabe quién debe qué.',
            'sub' => 'Registra cada pago entrante y saliente con el trabajo y contacto correctos.',
          ),
        ),
        'vendor-payments' => array(
          'icon' => 'wallet',
          'title' => 'Pagos a proveedores',
          'body' => 'Paga a subcontratistas y proveedores manteniendo cada pago vinculado al trabajo correcto.',
          'hero' => 'Paga a tus subcontratistas y mantén las cuentas en orden',
          'lead' => 'Registra y controla los pagos a subcontratistas y proveedores con cada dólar vinculado al trabajo correcto, para que la mano de obra y los materiales siempre caigan donde corresponde.',
          'rows' => array(
            0 => array(
              'heading' => 'Cada pago en el trabajo correcto',
              'text' => 'Cuando pagas a un subcontratista o proveedor, el costo se asocia al proyecto automáticamente. Se acabó preguntarse a qué trabajo correspondía realmente un cheque.',
              'points' => array(
                0 => 'Paga a subcontratistas y proveedores desde un solo lugar',
                1 => 'Los costos caen en el proyecto correcto',
                2 => 'Controla saldos corrientes por proveedor',
                3 => 'Mantén un registro limpio para los 1099',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Proveedor · Rivera Plumbing',
                'rows' => array(
                  0 => array(
                    'label' => 'Facturado',
                    'value' => '$6,400',
                  ),
                  1 => array(
                    'label' => 'Pagado',
                    'value' => '$4,000',
                  ),
                  2 => array(
                    'label' => 'Saldo',
                    'value' => '$2,400',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Vinculado a seguros y cumplimiento',
              'text' => 'Hive conecta a cada proveedor con sus certificados de seguro y su seguro de accidentes laborales, para que sigas pagando a los subcontratistas que te mantienen cubierto.',
              'points' => array(
                0 => 'Vinculado a los COI y coberturas del proveedor',
                1 => 'Consulta los saldos antes de volver a pagar',
                2 => 'Señala a los subcontratistas con papeleo vencido',
                3 => 'Alimenta el costeo de trabajos y tu contabilidad',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Pagar a los subcontratistas por Hive mantiene sincronizados el costo de mano de obra, los saldos y el cumplimiento — sin una hoja de cálculo aparte.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'user-group',
              'title' => 'Subcontratistas y proveedores',
              'body' => 'Paga a todos desde un solo lugar.',
            ),
            1 => array(
              'icon' => 'folder',
              'title' => 'Por trabajo',
              'body' => 'Los costos se asocian al proyecto correcto.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Saldos corrientes',
              'body' => 'Sabe cuánto se le debe a cada proveedor.',
            ),
            3 => array(
              'icon' => 'shield-check',
              'title' => 'Vinculado al cumplimiento',
              'body' => 'Vinculado a los COI y coberturas.',
            ),
            4 => array(
              'icon' => 'document-text',
              'title' => 'Listo para 1099',
              'body' => 'Registros limpios a fin de año.',
            ),
            5 => array(
              'icon' => 'calculator',
              'title' => 'Alimenta el costeo',
              'body' => 'La mano de obra cae en el costeo de trabajos.',
            ),
          ),
          'cta' => array(
            'heading' => 'Paga a tus subcontratistas sin perder el hilo.',
            'sub' => 'Cada pago vinculado al trabajo, al saldo y a su seguro.',
          ),
        ),
        'checks' => array(
          'icon' => 'pencil-square',
          'title' => 'Cheques',
          'body' => 'Imprime y registra cheques con el trabajo y la categoría ya rellenados.',
          'hero' => 'Emite cheques sin trabajo de más',
          'lead' => 'Imprime y registra cheques con el trabajo, la categoría y el proveedor ya rellenados — y míralos conciliarse solos con tu banco.',
          'rows' => array(
            0 => array(
              'heading' => 'Imprime y registra en un solo paso',
              'text' => 'Emite un cheque y Hive lo registra al mismo tiempo como un gasto en el trabajo correcto. El papel y los libros quedan perfectamente sincronizados.',
              'points' => array(
                0 => 'Imprime cheques en tus formularios',
                1 => 'Registrado como gasto automáticamente',
                2 => 'Trabajo y categoría prellenados',
                3 => 'Numeración secuencial ordenada',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Cheque n.º 1042',
                'rows' => array(
                  0 => array(
                    'label' => 'Pagar a',
                    'value' => 'Rivera Plumbing',
                  ),
                  1 => array(
                    'label' => 'Trabajo',
                    'value' => 'Maple St',
                  ),
                  2 => array(
                    'label' => 'Importe',
                    'value' => '$2,400.00',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Se concilia solo',
              'text' => 'Cuando el cheque se cobra, la transacción bancaria coincide con el registro que ya hiciste. La conciliación deja de ser una carga.',
              'points' => array(
                0 => 'Coincide con la transacción bancaria cobrada',
                1 => 'Sin doble registro a fin de mes',
                2 => 'Detecta cheques pendientes con facilidad',
                3 => 'Un rastro limpio de cada pago',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'El cheque que imprimiste ya está en tus libros en el trabajo correcto — así que conciliar es solo confirmar, no volver a teclear.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'printer',
              'title' => 'Listo para imprimir',
              'body' => 'Emite cheques en tus formularios.',
            ),
            1 => array(
              'icon' => 'folder',
              'title' => 'Trabajo prellenado',
              'body' => 'El proyecto correcto, automáticamente.',
            ),
            2 => array(
              'icon' => 'tag',
              'title' => 'Categoría asignada',
              'body' => 'Consistente para libros limpios.',
            ),
            3 => array(
              'icon' => 'hashtag',
              'title' => 'Numerado',
              'body' => 'Secuencial y ordenado.',
            ),
            4 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Auto-conciliación',
              'body' => 'Se concilia con tu banco.',
            ),
            5 => array(
              'icon' => 'document-check',
              'title' => 'Rastro limpio',
              'body' => 'Un registro por cada cheque.',
            ),
          ),
          'cta' => array(
            'heading' => 'Convierte los cheques en un paso, no en tres.',
            'sub' => 'Imprime, registra y concilia con una sola acción.',
          ),
        ),
        'banks' => array(
          'icon' => 'building-library',
          'title' => 'Bancos',
          'body' => 'Conecta cuentas para recibir transacciones en vivo y conciliar.',
          'hero' => 'Tu banco trabajando para ti',
          'lead' => 'Conecta tus cuentas para un flujo en vivo de transacciones que se emparejan solas con gastos, cheques y proveedores — y conciliar toma minutos.',
          'rows' => array(
            0 => array(
              'heading' => 'Transacciones en vivo, automáticamente',
              'text' => 'Vincula una vez tus cuentas y tarjetas de empresa. Las nuevas transacciones llegan solas, listas para emparejarse con los costos que ya registraste.',
              'points' => array(
                0 => 'Conexión segura con tus cuentas',
                1 => 'Las transacciones se actualizan solas',
                2 => 'Tarjetas y cuenta corriente en una sola vista',
                3 => 'Nada que importar a mano',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Movimientos recientes · Operativa',
                'rows' => array(
                  0 => array(
                    'icon' => 'building-library',
                    'label' => 'Home Depot',
                    'sub' => '-$842.10 · emparejado',
                  ),
                  1 => array(
                    'icon' => 'building-library',
                    'label' => 'Cheque n.º 1042',
                    'sub' => '-$2,400.00 · emparejado',
                  ),
                  2 => array(
                    'icon' => 'building-library',
                    'label' => 'Depósito de cliente',
                    'sub' => '+$10,000 · revisar',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Concilia en minutos',
              'text' => 'Hive empareja cada transacción con el gasto, cheque o pago a proveedor correcto. Confirmas las coincidencias y tus libros están listos.',
              'points' => array(
                0 => 'Emparejado automáticamente con tus registros',
                1 => 'Detecta rápido lo que falta',
                2 => 'Mantén los saldos exactos',
                3 => 'Sin conciliar en hojas de cálculo',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un banco en vivo que se empareja solo convierte horas de conciliación de fin de mes en una revisión rápida.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'link',
              'title' => 'Conectado',
              'body' => 'Vincula cuentas y tarjetas una vez.',
            ),
            1 => array(
              'icon' => 'bolt',
              'title' => 'Movimientos en vivo',
              'body' => 'Las transacciones se actualizan solas.',
            ),
            2 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Conciliación automática',
              'body' => 'Coincide con tus registros.',
            ),
            3 => array(
              'icon' => 'shield-check',
              'title' => 'Seguro',
              'body' => 'Conexión de nivel bancario.',
            ),
            4 => array(
              'icon' => 'check-circle',
              'title' => 'Conciliación fácil',
              'body' => 'Confirma y listo.',
            ),
            5 => array(
              'icon' => 'scale',
              'title' => 'Preciso',
              'body' => 'Saldos en los que puedes confiar.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja que tu conexión bancaria haga la conciliación.',
            'sub' => 'Conecta una vez y concilia en minutos, no en horas.',
          ),
        ),
        'transaction-matching' => array(
          'icon' => 'arrows-right-left',
          'title' => 'Conciliación de transacciones',
          'body' => 'Las transacciones bancarias se emparejan solas con el proveedor, gasto y cheque correcto.',
          'hero' => 'Transacciones que se concilian solas',
          'lead' => 'Hive empareja cada transacción bancaria con el proveedor, gasto y cheque correcto automáticamente, así tus libros quedan limpios sin ordenar a mano.',
          'rows' => array(
            0 => array(
              'heading' => 'Conciliación inteligente desde el primer día',
              'text' => 'Nuestra conciliación aprende tus proveedores y patrones, y luego conecta cada transacción entrante con el costo que ya registraste, o sugiere la coincidencia más cercana.',
              'points' => array(
                0 => 'Empareja con proveedor, gasto o cheque',
                1 => 'Aprende tus patrones recurrentes',
                2 => 'Sugiere la mejor coincidencia para confirmar',
                3 => 'Marca todo lo inesperado',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Conciliado hoy',
                'rows' => array(
                  0 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'Ferguson → Gasto',
                    'sub' => '$1,260 · Plomería',
                  ),
                  1 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'Cheque #1042 → Rivera',
                    'sub' => '$2,400 · Maple St',
                  ),
                  2 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'Combustible → Vehículo',
                    'sub' => '$88.40',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Detecta lo que no encaja',
              'text' => 'Los cargos sin conciliar o duplicados aparecen al instante, así los errores y la doble facturación se detectan antes de afectar tus cifras.',
              'points' => array(
                0 => 'Muestra transacciones sin conciliar',
                1 => 'Detecta duplicados automáticamente',
                2 => 'Mantiene precisos los costos por obra',
                3 => 'Confía en tus reportes',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Cuando la conciliación es automática, las únicas transacciones que revisas son las que de verdad te necesitan.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'sparkles',
              'title' => 'Coincidencias inteligentes',
              'body' => 'Aprende tus proveedores y patrones.',
            ),
            1 => array(
              'icon' => 'check-circle',
              'title' => 'Confirmación con un toque',
              'body' => 'Aprueba las coincidencias sugeridas al instante.',
            ),
            2 => array(
              'icon' => 'document-duplicate',
              'title' => 'Antiduplicados',
              'body' => 'Detecta cargos dobles.',
            ),
            3 => array(
              'icon' => 'flag',
              'title' => 'Excepciones',
              'body' => 'Solo marca lo que te necesita.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Exacto por obra',
              'body' => 'Mantiene los costos en la obra correcta.',
            ),
            5 => array(
              'icon' => 'chart-bar',
              'title' => 'Fiable',
              'body' => 'Reportes en los que puedes confiar.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja de ordenar transacciones a mano.',
            'sub' => 'Deja que la conciliación una los puntos y solo te muestre las excepciones.',
          ),
        ),
        'reimbursements' => array(
          'icon' => 'arrow-uturn-left',
          'title' => 'Reembolsos',
          'body' => 'Controla lo que la empresa debe a la cuadrilla y a los dueños, y págalo sin líos.',
          'hero' => 'Devuelve el dinero a tu gente, sin notas adhesivas',
          'lead' => 'Controla cada gasto de bolsillo que cubre tu cuadrilla y tus dueños, y reembólsalo con un registro que se vincula a la obra.',
          'rows' => array(
            0 => array(
              'heading' => 'Gastos de bolsillo, registrados',
              'text' => 'Cuando alguien compra materiales con su propia tarjeta, regístralo como gasto reembolsable en la obra. Nada se olvida ni se paga dos veces.',
              'points' => array(
                0 => 'Registra gastos de bolsillo en una obra',
                1 => 'Adjunta el recibo como comprobante',
                2 => 'Controla a quién le debes y cuánto',
                3 => 'Evita pagar el mismo costo dos veces',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Adeudado a · Greg M.',
                'rows' => array(
                  0 => array(
                    'label' => 'Madera (tarjeta personal)',
                    'value' => '$214.80',
                  ),
                  1 => array(
                    'label' => 'Ferretería',
                    'value' => '$63.20',
                  ),
                  2 => array(
                    'label' => 'A reembolsar',
                    'value' => '$278.00',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Salda cuentas sin líos',
              'text' => 'Reembolsa el saldo acumulado en un solo pago y Hive lo registra en la obra y categoría correcta, manteniendo precisos los costos y los libros.',
              'points' => array(
                0 => 'Paga el saldo acumulado',
                1 => 'Registrado en la obra',
                2 => 'Mantiene preciso el costo de obra',
                3 => 'Historial claro para todos',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una cuadrilla a la que reembolsas rápido y bien es una cuadrilla que sigue comprando lo que la obra necesita.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrow-uturn-left',
              'title' => 'Reembolsable',
              'body' => 'Marca los gastos de bolsillo.',
            ),
            1 => array(
              'icon' => 'paper-clip',
              'title' => 'Con recibo',
              'body' => 'Comprobante adjunto a cada uno.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Adeudo acumulado',
              'body' => 'Sabe a quién se le debe qué.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'En la obra',
              'body' => 'El costo cae en la obra.',
            ),
            4 => array(
              'icon' => 'banknotes',
              'title' => 'Págalo',
              'body' => 'Salda en un solo pago.',
            ),
            5 => array(
              'icon' => 'clock',
              'title' => 'Historial',
              'body' => 'Un rastro claro para todos.',
            ),
          ),
          'cta' => array(
            'heading' => 'Reembolsa a tu cuadrilla de forma fácil.',
            'sub' => 'Captura los gastos de bolsillo y salda cuentas con un registro limpio.',
          ),
        ),
        'distributions' => array(
          'icon' => 'receipt-percent',
          'title' => 'Distribuciones',
          'body' => 'Mantén los retiros de dueños y las distribuciones organizados y listos para reportar.',
          'hero' => 'Retiros de dueños, organizados y listos para reportar',
          'lead' => 'Registra los retiros de dueños y las distribuciones de forma que queden limpios para tu contador y claros para ti en temporada de impuestos.',
          'rows' => array(
            0 => array(
              'heading' => 'Controla cada retiro',
              'text' => 'Registra las distribuciones a medida que ocurren, aparte de los costos de obra y gastos, para que las cifras del negocio y tu retiro personal nunca se mezclen.',
              'points' => array(
                0 => 'Registra los retiros de dueños sin líos',
                1 => 'Manténlos fuera de los costos de obra',
                2 => 'Reparte entre varios dueños',
                3 => 'Vinculados a las cuentas correctas',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Distribuciones · del año',
                'rows' => array(
                  0 => array(
                    'label' => 'Dueño A',
                    'value' => '$42,000',
                  ),
                  1 => array(
                    'label' => 'Dueño B',
                    'value' => '$38,500',
                  ),
                  2 => array(
                    'label' => 'Total',
                    'value' => '$80,500',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Listo para tu contador',
              'text' => 'Todo está categorizado y listo para reportar, así que la entrega en temporada de impuestos es una descarga, no una reconstrucción.',
              'points' => array(
                0 => 'Reportable por dueño y periodo',
                1 => 'Categorías limpias todo el año',
                2 => 'Entrega fácil a tu contador',
                3 => 'Sin apuros en temporada de impuestos',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Mantener los retiros limpios todo el año hace que la temporada de impuestos sea una exportación rápida, no una limpieza dolorosa.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'receipt-percent',
              'title' => 'Retiros de dueños',
              'body' => 'Registrados a medida que ocurren.',
            ),
            1 => array(
              'icon' => 'users',
              'title' => 'Multipropietario',
              'body' => 'Repartido entre socios.',
            ),
            2 => array(
              'icon' => 'tag',
              'title' => 'Categorizado',
              'body' => 'Libros limpios todo el año.',
            ),
            3 => array(
              'icon' => 'folder-minus',
              'title' => 'Separado',
              'body' => 'Fuera del costeo de obras.',
            ),
            4 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Reportable',
              'body' => 'Por socio y período.',
            ),
            5 => array(
              'icon' => 'arrow-down-tray',
              'title' => 'Exportar',
              'body' => 'Entrega a tu contador.',
            ),
          ),
          'cta' => array(
            'heading' => 'Mantén tus retiros limpios todo el año.',
            'sub' => 'Distribuciones organizadas y reportables que simplifican la temporada de impuestos.',
          ),
        ),
        'line-items' => array(
          'icon' => 'list-bullet',
          'title' => 'Partidas y asignaciones',
          'body' => 'Detalla costos por partida y concíliialos con las asignaciones del cliente hasta la línea.',
          'hero' => 'Detállalo todo—y protege tus asignaciones',
          'lead' => 'Desglosa los costos hasta la línea y concíliialos con cada asignación del cliente, para detectar los excesos antes de que te cuesten dinero.',
          'rows' => array(
            0 => array(
              'heading' => 'Detalle hasta la línea',
              'text' => 'Registra los costos como partidas detalladas, no como sumas globales. Tú y tu cliente ven exactamente adónde va el dinero en cada selección y categoría.',
              'points' => array(
                0 => 'Detalla los costos línea por línea',
                1 => 'Agrupa líneas por categoría o habitación',
                2 => 'Vincula líneas a la obra correcta',
                3 => 'Claridad total para los clientes',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Asignación de azulejos',
                'rows' => array(
                  0 => array(
                    'label' => 'Asignación',
                    'value' => '$2,500',
                  ),
                  1 => array(
                    'label' => 'Costo detallado',
                    'value' => '$2,840',
                  ),
                  2 => array(
                    'label' => 'Exceso',
                    'value' => '+$340',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Asignaciones que se sostienen',
              'text' => 'Hive concilia tus partidas con la asignación del cliente&rsquo;e y marca los excesos, para que la conversación ocurra antes de la factura, no después.',
              'points' => array(
                0 => 'Concilia líneas con asignaciones',
                1 => 'Marca los excesos automáticamente',
                2 => 'Factura los excesos con confianza',
                3 => 'No dejas dinero sobre la mesa',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Las partidas conciliadas con las asignaciones significan que cobras por las mejoras que eligen los clientes—siempre.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'list-bullet',
              'title' => 'Detallado',
              'body' => 'Costos desglosados a la línea.',
            ),
            1 => array(
              'icon' => 'rectangle-group',
              'title' => 'Agrupado',
              'body' => 'Por categoría o habitación.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Conciliado',
              'body' => 'Líneas vs asignaciones.',
            ),
            3 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Alertas de exceso',
              'body' => 'Detectados antes de facturar.',
            ),
            4 => array(
              'icon' => 'banknotes',
              'title' => 'Factura mejoras',
              'body' => 'Cobra por los cambios.',
            ),
            5 => array(
              'icon' => 'eye',
              'title' => 'Transparente',
              'body' => 'Claro para los clientes.',
            ),
          ),
          'cta' => array(
            'heading' => 'No vuelvas a absorber un exceso de asignación.',
            'sub' => 'Detalla hasta la línea y concilia con cada asignación.',
          ),
        ),
        'estimates-invoices' => array(
          'icon' => 'document-text',
          'title' => 'Presupuestos y facturas',
          'body' => 'Envía presupuestos y facturas con tu marca y convierte las aprobaciones en obras.',
          'hero' => 'Del presupuesto a la factura al cobro',
          'lead' => 'Envía presupuestos con tu marca, convierte las aprobaciones en obras activas y factura el trabajo terminado—todo sin salir de Hive.',
          'rows' => array(
            0 => array(
              'heading' => 'Con tu marca y profesionales',
              'text' => 'Envía presupuestos claros y detallados que te hacen ver como el profesional que eres. Los clientes aprueban en línea y la obra queda lista para empezar.',
              'points' => array(
                0 => 'Presupuestos y facturas con tu marca',
                1 => 'Detallados y fáciles de leer',
                2 => 'Aprobación en línea y firma electrónica',
                3 => 'Las aprobaciones se vuelven obras activas',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Presupuesto #1042',
                'rows' => array(
                  0 => array(
                    'label' => 'Ebanistería',
                    'value' => '$8,400',
                  ),
                  1 => array(
                    'label' => 'Encimeras',
                    'value' => '$3,950',
                  ),
                  2 => array(
                    'label' => 'Total',
                    'value' => '$14,450',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Factura y cobra',
              'text' => 'Factura pagos parciales o finales directamente desde el alcance aprobado. Todo se vincula al costeo de obras y a tus libros.',
              'points' => array(
                0 => 'Factura desde el alcance aprobado',
                1 => 'Facturación parcial o final',
                2 => 'Conectado al costeo de obras',
                3 => 'Registro claro de lo pagado',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Cuando los presupuestos fluyen a obras y facturas, dejas de reescribir cifras y empiezas a cobrar más rápido.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-text',
              'title' => 'Presupuestos',
              'body' => 'Con tu marca y detallados.',
            ),
            1 => array(
              'icon' => 'pencil-square',
              'title' => 'Firma electrónica',
              'body' => 'Aprueba en línea en segundos.',
            ),
            2 => array(
              'icon' => 'arrow-path',
              'title' => 'A obras',
              'body' => 'Las aprobaciones se vuelven proyectos.',
            ),
            3 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Facturas',
              'body' => 'Factura el trabajo terminado.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Vinculado al costeo',
              'body' => 'Se vincula al costeo de obras.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Claridad de cobros',
              'body' => 'Sabe qué está saldado.',
            ),
          ),
          'cta' => array(
            'heading' => 'Gana la oferta y factúrala—en un solo lugar.',
            'sub' => 'Presupuestos con tu marca que fluyen directo a obras y facturas.',
          ),
        ),
        'sheets' => array(
          'icon' => 'document-currency-dollar',
          'title' => 'Estados',
          'body' => 'Balances y estados de resultados generados a partir de tus datos en vivo.',
          'hero' => 'Estados financieros que se generan solos',
          'lead' => 'Tu balance y estado de resultados se generan a partir de datos en vivo—siempre al día, siempre listos, sin batallar con hojas de cálculo.',
          'rows' => array(
            0 => array(
              'heading' => 'Estado de resultados en vivo',
              'text' => 'Cada gasto, pago y factura fluye a un estado de resultados actualizado al minuto—no del trimestre pasado. Ve cómo va realmente el negocio cuando quieras.',
              'points' => array(
                0 => 'Estado de resultados con datos en vivo',
                1 => 'Balance siempre al día',
                2 => 'Filtra por período y obra',
                3 => 'Sin exportaciones contables manuales',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Resultados · Este mes',
                'rows' => array(
                  0 => array(
                    'label' => 'Ingresos',
                    'value' => '$84,200',
                  ),
                  1 => array(
                    'label' => 'Costos',
                    'value' => '$58,640',
                  ),
                  2 => array(
                    'label' => 'Neto',
                    'value' => '$25,560',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Listos para quien los pida',
              'text' => 'Cuando tu contador, prestamista o socio necesita cifras, ya están listas. Exporta un estado limpio en segundos.',
              'points' => array(
                0 => 'Entrega rápido a tu contador',
                1 => 'Estados que los prestamistas confían',
                2 => 'Siempre conciliados con tu feed',
                3 => 'Exporta cuando lo necesites',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Estados financieros siempre al día significan que decides con cifras reales, no con corazonadas.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Estado de resultados',
              'body' => 'En vivo, no del trimestre pasado.',
            ),
            1 => array(
              'icon' => 'scale',
              'title' => 'Balance',
              'body' => 'Siempre al día.',
            ),
            2 => array(
              'icon' => 'funnel',
              'title' => 'Filtrable',
              'body' => 'Por período y obra.',
            ),
            3 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Conciliado',
              'body' => 'Vinculado a tu feed bancario.',
            ),
            4 => array(
              'icon' => 'arrow-down-tray',
              'title' => 'Exportable',
              'body' => 'Entrégalo en segundos.',
            ),
            5 => array(
              'icon' => 'chart-bar',
              'title' => 'Listo para decidir',
              'body' => 'Números reales, cuando quieras.',
            ),
          ),
          'cta' => array(
            'heading' => 'Conoce tus números sin la hoja de cálculo.',
            'sub' => 'Estado de resultados y balance en vivo, generados con tus datos reales.',
          ),
        ),
        'categories' => array(
          'icon' => 'tag',
          'title' => 'Categorías',
          'body' => 'Categorías consistentes mantienen tus libros y reportes confiables.',
          'hero' => 'Categorías consistentes, libros confiables',
          'lead' => 'Un conjunto limpio de categorías aplicado en todas partes hace que tus reportes signifiquen algo de verdad, y la temporada de impuestos duele mucho menos.',
          'rows' => array(
            0 => array(
              'heading' => 'Un conjunto consistente',
              'text' => 'Define una vez las categorías que encajan con tu negocio y Hive las aplica en gastos, cheques y pagos para que nada quede mal codificado.',
              'points' => array(
                0 => 'Define categorías que encajen con tu oficio',
                1 => 'Aplicadas en cada transacción',
                2 => 'Sugeridas automáticamente sobre la marcha',
                3 => 'Se acabaron los errores de etiquetado sueltos',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Categorías principales · Este mes',
                'rows' => array(
                  0 => array(
                    'icon' => 'tag',
                    'label' => 'Materiales',
                    'sub' => '$32,400',
                  ),
                  1 => array(
                    'icon' => 'tag',
                    'label' => 'Mano de obra',
                    'sub' => '$21,800',
                  ),
                  2 => array(
                    'icon' => 'tag',
                    'label' => 'Vehículo y combustible',
                    'sub' => '$3,140',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Reportes en los que puedes confiar',
              'text' => 'Cuando todo se codifica igual, tu estado de resultados y los costos por obra dicen la verdad, y tu contador te lo agradece.',
              'points' => array(
                0 => 'Estado de resultados fiable',
                1 => 'Costos por obra precisos',
                2 => 'Preparación de impuestos más limpia',
                3 => 'Detecta tendencias con confianza',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Las categorías consistentes son el cimiento silencioso bajo cada reporte en el que de verdad confías.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'tag',
              'title' => 'Conjunto propio',
              'body' => 'A la medida de tu oficio.',
            ),
            1 => array(
              'icon' => 'sparkles',
              'title' => 'Autosugeridas',
              'body' => 'Codificadas al vuelo.',
            ),
            2 => array(
              'icon' => 'arrows-right-left',
              'title' => 'En todo',
              'body' => 'En todas las transacciones.',
            ),
            3 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Estado de resultados limpio',
              'body' => 'Reportes que cuadran.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Costeo preciso',
              'body' => 'Obras bien codificadas.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Listo para impuestos',
              'body' => 'Menos limpieza al cierre del año.',
            ),
          ),
          'cta' => array(
            'heading' => 'Construye libros en los que de verdad puedas confiar.',
            'sub' => 'Un conjunto de categorías consistente aplicado en todo.',
          ),
        ),
        'job-costing' => array(
          'icon' => 'calculator',
          'title' => 'Costeo por obra',
          'body' => 'Ve el costo real y el margen de cada proyecto a medida que se mueve el dinero.',
          'hero' => 'Conoce tu margen en cada obra',
          'lead' => 'Ve el costo real y el margen en vivo de cada proyecto a medida que se mueven gastos, mano de obra y pagos, para enterarte de que te pasaste antes de que sea tarde.',
          'rows' => array(
            0 => array(
              'heading' => 'Costo que se calcula solo',
              'text' => 'Materiales, mano de obra y pagos a subcontratistas caen en la obra automáticamente. Tu costo acumulado siempre está al día sin que sumes nada.',
              'points' => array(
                0 => 'Materiales, mano de obra y subcontratistas juntos',
                1 => 'Costo acumulado siempre al día',
                2 => 'Compara contra el presupuesto',
                3 => 'Sin sumar a mano',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Maple St · Margen',
                'rows' => array(
                  0 => array(
                    'label' => 'Contrato',
                    'value' => '$48,000',
                  ),
                  1 => array(
                    'label' => 'Costo a la fecha',
                    'value' => '$30,100',
                  ),
                  2 => array(
                    'label' => 'Margen proyectado',
                    'value' => '24%',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Detecta los sobrecostos a tiempo',
              'text' => 'Cuando una obra empieza a pasarse, lo ves cuando todavía puedes hacer algo, no en la factura final.',
              'points' => array(
                0 => 'Detecta sobrecostos en tiempo real',
                1 => 'Protege tu margen',
                2 => 'Decide antes de que sea tarde',
                3 => 'Cotiza mejor la próxima obra',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'El costeo en vivo es la diferencia entre enterarte de que perdiste dinero y evitarlo.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'calculator',
              'title' => 'Costo real',
              'body' => 'Materiales, mano de obra, subs.',
            ),
            1 => array(
              'icon' => 'bolt',
              'title' => 'En vivo',
              'body' => 'Se actualiza con el dinero.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Vs presupuesto',
              'body' => 'Compara con tu oferta.',
            ),
            3 => array(
              'icon' => 'chart-bar',
              'title' => 'Margen',
              'body' => 'Ve la ganancia por obra.',
            ),
            4 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Alertas de sobrecosto',
              'body' => 'Detéctalo a tiempo.',
            ),
            5 => array(
              'icon' => 'light-bulb',
              'title' => 'Mejores ofertas',
              'body' => 'Aprende de datos reales.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja de adivinar si una obra dio ganancia.',
            'sub' => 'Costo y margen en vivo de cada proyecto, en tiempo real.',
          ),
        ),
        'lien-waivers' => array(
          'icon' => 'document-check',
          'title' => 'Renuncias de gravamen',
          'body' => 'Envía y recolecta renuncias firmadas con enlaces seguros y sin cuenta.',
          'hero' => 'Recolecta renuncias de gravamen sin perseguir a nadie',
          'lead' => 'Envía renuncias y recolecta firmas con enlaces seguros, sin cuentas ni impresión, para que el papeleo que protege tus pagos siempre esté listo.',
          'rows' => array(
            0 => array(
              'heading' => 'Envía en segundos',
              'text' => 'Genera la renuncia correcta y envía un enlace seguro al subcontratista o proveedor. Firma en cualquier dispositivo, sin iniciar sesión.',
              'points' => array(
                0 => 'Renuncias condicionales e incondicionales',
                1 => 'Enlaces de firma seguros y sin cuenta',
                2 => 'Firma en cualquier teléfono o computadora',
                3 => 'Vinculadas a la obra y al pago',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Renuncias · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Firmada · 12/6',
                  ),
                  1 => array(
                    'icon' => 'document-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Firmada · 14/6',
                  ),
                  2 => array(
                    'icon' => 'clock',
                    'label' => 'Summit Drywall',
                    'sub' => 'Enviada · pendiente',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Protegidas y organizadas',
              'text' => 'Cada renuncia firmada se guarda junto a la obra y al pago, así que cuando un contratista general o un banco pregunta, la prueba está a un clic.',
              'points' => array(
                0 => 'Guardadas junto a la obra',
                1 => 'Un clic para recuperar',
                2 => 'Rastrea quién firmó y quién no',
                3 => 'Protege tu derecho al pago',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Las renuncias recolectadas a tiempo mantienen tus pagos fluyendo y tus proyectos libres de gravámenes.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-check',
              'title' => 'Renuncia correcta',
              'body' => 'Condicional o incondicional.',
            ),
            1 => array(
              'icon' => 'link',
              'title' => 'Enlaces seguros',
              'body' => 'Firma sin cuenta.',
            ),
            2 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Cualquier dispositivo',
              'body' => 'Firma desde el teléfono.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'En la obra',
              'body' => 'Guardadas con el proyecto.',
            ),
            4 => array(
              'icon' => 'clock',
              'title' => 'Rastrea el estado',
              'body' => 'Ve quién falta.',
            ),
            5 => array(
              'icon' => 'shield-check',
              'title' => 'Protegido',
              'body' => 'Protege tus pagos.',
            ),
          ),
          'cta' => array(
            'heading' => 'Nunca vuelvas a perseguir una renuncia.',
            'sub' => 'Envía enlaces seguros y recoge firmas sin complicaciones.',
          ),
        ),
        'insurance-certificates' => array(
          'icon' => 'shield-check',
          'title' => 'Certificados de seguro',
          'body' => 'Guarda los certificados de seguro y recibe alertas antes de que venzan.',
          'hero' => 'Mantente cubierto con cada COI archivado',
          'lead' => 'Mantén cada certificado de seguro organizado y recibe alertas antes de que alguno caduque, para no quedar nunca expuesto en una obra.',
          'rows' => array(
            0 => array(
              'heading' => 'Cada COI en un solo lugar',
              'text' => 'Guarda los certificados de cada subcontratista y proveedor, vinculados al proveedor y a los trabajos que realizan. Se acabó buscar en el correo la prueba de cobertura.',
              'points' => array(
                0 => 'Guarda los COI por proveedor',
                1 => 'Vinculados a los trabajos que realizan',
                2 => 'Ve la cobertura de un vistazo',
                3 => 'Solicita actualizaciones con un toque',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Cobertura · Subcontratistas',
                'rows' => array(
                  0 => array(
                    'icon' => 'shield-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Válido hasta 30/11',
                  ),
                  1 => array(
                    'icon' => 'shield-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Válido hasta 15/9',
                  ),
                  2 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'Vence en 9 días',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Alertas antes de que caduquen',
              'text' => 'Hive vigila las fechas de vencimiento y te avisa con antelación, para que consigas el certificado renovado antes de que un subcontratista pise la obra sin cobertura.',
              'points' => array(
                0 => 'Alertas automáticas de vencimiento',
                1 => 'Detecta las caducidades antes de que ocurran',
                2 => 'Protégete de la responsabilidad',
                3 => 'Mantén contentos a contratistas generales y prestamistas',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un COI vencido que no detectaste es un reclamo esperando caer sobre ti. Hive se asegura de que lo detectes.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'shield-check',
              'title' => 'COI archivados',
              'body' => 'Cada certificado guardado.',
            ),
            1 => array(
              'icon' => 'user-group',
              'title' => 'Por proveedor',
              'body' => 'Vinculado a cada subcontratista.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Alertas de vencimiento',
              'body' => 'Aviso con antelación.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Por obra',
              'body' => 'Vinculados a los proyectos.',
            ),
            4 => array(
              'icon' => 'envelope',
              'title' => 'Solicita actualizaciones',
              'body' => 'Pide a los agentes al instante.',
            ),
            5 => array(
              'icon' => 'scale',
              'title' => 'Menos responsabilidad',
              'body' => 'Nunca sin cobertura.',
            ),
          ),
          'cta' => array(
            'heading' => 'Nunca dejes que la cobertura caduque en una obra.',
            'sub' => 'Cada COI archivado con alertas antes de que venza.',
          ),
        ),
        'workers-comp' => array(
          'icon' => 'clipboard-document-check',
          'title' => 'Compensación laboral',
          'body' => 'Verifica la cobertura y recibe alertas antes de que caduque.',
          'hero' => 'Mantén la compensación laboral al día, automáticamente',
          'lead' => 'Verifica que cada subcontratista tenga compensación laboral y recibe avisos antes de que caduque cualquier póliza, para que una lesión nunca se convierta en tu problema.',
          'rows' => array(
            0 => array(
              'heading' => 'Verifica antes de que trabajen',
              'text' => 'Confirma la cobertura de compensación de cada subcontratista por adelantado y guarda la prueba archivada, vinculada al proveedor y a la obra. Sin cobertura, sin sorpresas.',
              'points' => array(
                0 => 'Verifica la compensación de cada subcontratista',
                1 => 'Pruebas guardadas por proveedor',
                2 => 'Vinculadas a los trabajos que realizan',
                3 => 'Marca a cualquiera sin cobertura',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Compensación laboral · Subcontratistas',
                'rows' => array(
                  0 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Activa',
                  ),
                  1 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Activa',
                  ),
                  2 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'Caduca 15/7',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Alertas que te protegen',
              'text' => 'Hive vigila las fechas de las pólizas y te avisa antes de que caduque la cobertura, para que nunca respondas por una cuadrilla sin seguro en tu obra.',
              'points' => array(
                0 => 'Alertas anticipadas de vencimiento',
                1 => 'Protégete de los reclamos',
                2 => 'Siempre listo para auditorías',
                3 => 'Tranquilidad en cada obra',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una sola lesión sin seguro puede hundir a un contratista pequeño. Hive mantiene la compensación al día para que nunca ocurra.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Verificado',
              'body' => 'Cobertura confirmada.',
            ),
            1 => array(
              'icon' => 'user-group',
              'title' => 'Por subcontratista',
              'body' => 'Prueba por proveedor.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Alertas de vencimiento',
              'body' => 'Aviso anticipado.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Por obra',
              'body' => 'Vinculado a los proyectos.',
            ),
            4 => array(
              'icon' => 'shield-check',
              'title' => 'Protegido',
              'body' => 'Reclamos cubiertos.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Listo para auditorías',
              'body' => 'Prueba a mano.',
            ),
          ),
          'cta' => array(
            'heading' => 'Asegúrate de que cada subcontratista tenga cobertura.',
            'sub' => 'Verifica la compensación laboral y recibe alertas antes de que caduque.',
          ),
        ),
        'timesheets-payroll' => array(
          'icon' => 'clock',
          'title' => 'Hojas de horas y nómina',
          'body' => 'Aprueba las horas de la cuadrilla y paga a tu equipo desde un mismo lugar.',
          'hero' => 'De las horas al pago, sin hojas de cálculo',
          'lead' => 'Las cuadrillas registran sus horas desde la obra, tú apruebas con un toque y la nómina fluye desde la misma pantalla, con el costo de mano de obra cayendo en cada trabajo.',
          'rows' => array(
            0 => array(
              'heading' => 'Horas desde la obra',
              'text' => 'Tu cuadrilla registra el tiempo en el trabajo y la tarea correctos desde su teléfono. Revisas la semana y apruebas sin perseguir tarjetas de horas en papel.',
              'points' => array(
                0 => 'Seguimiento de tiempo móvil por trabajo',
                1 => 'Aprobación de hojas de horas con un toque',
                2 => 'La mano de obra entra en el costeo de trabajos',
                3 => 'Sin tarjetas de horas en papel',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Esta semana · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'clock',
                    'label' => 'Greg M. · Plomería',
                    'sub' => '32,5 h',
                  ),
                  1 => array(
                    'icon' => 'clock',
                    'label' => 'Tony R. · Estructura',
                    'sub' => '28,0 h',
                  ),
                  2 => array(
                    'icon' => 'clock',
                    'label' => 'Sam K. · Azulejos',
                    'sub' => '18,0 h',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Paga a partir de horas aprobadas',
              'text' => 'El tiempo aprobado pasa directo a los pagos, con un saldo actualizado por trabajador y registros que coinciden con tus libros.',
              'points' => array(
                0 => 'Nómina a partir de horas aprobadas',
                1 => 'Saldo actualizado por trabajador',
                2 => 'Registros que coinciden con tus libros',
                3 => 'Paga a tu cuadrilla a tiempo',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Cuando las horas, el costeo y la nómina comparten un solo flujo, tu cuadrilla cobra correctamente y los costos de tus trabajos se mantienen fieles.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clock',
              'title' => 'Tiempo móvil',
              'body' => 'Ficha desde la obra.',
            ),
            1 => array(
              'icon' => 'check-circle',
              'title' => 'Aprueba',
              'body' => 'Revisa las horas con un toque.',
            ),
            2 => array(
              'icon' => 'banknotes',
              'title' => 'Nómina',
              'body' => 'Paga a partir del tiempo aprobado.',
            ),
            3 => array(
              'icon' => 'scale',
              'title' => 'Saldos',
              'body' => 'Totales por trabajador.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Con costeo por trabajo',
              'body' => 'Mano de obra en el trabajo correcto.',
            ),
            5 => array(
              'icon' => 'arrows-right-left',
              'title' => 'En sincronía',
              'body' => 'Coincide con tus libros.',
            ),
          ),
          'cta' => array(
            'heading' => 'Saca la nómina de la mesa de la cocina.',
            'sub' => 'Horas de campo, aprobación con un toque y pago desde el mismo lugar.',
          ),
        ),
      ),
    ),
    'estimates' => array(
      'label' => 'Presupuestos y documentos',
      'eyebrow' => 'Presupuestos y documentos',
      'grid_heading' => 'Todo lo que necesitas para cerrar trabajos',
      'cards' => array(
        'ai-estimates' => array(
          'icon' => 'document-text',
          'title' => 'Presupuestos con IA',
          'body' => 'Redacta presupuestos detallados en minutos y ajústalos a tu manera.',
          'hero' => 'Redacta un presupuesto ganador en minutos',
          'lead' => 'Describe el trabajo y deja que la IA redacte un presupuesto detallado que puedes ajustar, marcar y enviar—así presupuestas más obras en menos tiempo.',
          'rows' => array(
            0 => array(
              'heading' => 'Del alcance al presupuesto, rápido',
              'text' => 'Escribe el alcance o parte de un trabajo anterior y Hive redacta partidas detalladas con cantidades y precios. Tú ajustas, marcas y envías.',
              'points' => array(
                0 => 'La IA redacta las partidas detalladas por ti',
                1 => 'Empieza de cero o desde un trabajo anterior',
                2 => 'Ajusta cantidades y precios con libertad',
                3 => 'Envía con tu marca, listo para firmar',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Borrador · Remodelación de cocina',
                'rows' => array(
                  0 => array(
                    'label' => 'Gabinetes e instalación',
                    'value' => '$8,400',
                  ),
                  1 => array(
                    'label' => 'Encimeras',
                    'value' => '$3,950',
                  ),
                  2 => array(
                    'label' => 'Azulejo y salpicadero',
                    'value' => '$2,100',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Más ofertas, más ganadas',
              'text' => 'Presupuestos más rápidos significan responder mientras el cliente está interesado. El acabado profesional te distingue del contratista que aún garabatea en una libreta.',
              'points' => array(
                0 => 'Responde mientras los clientes están interesados',
                1 => 'Luce más profesional que el resto',
                2 => 'Reutiliza las plantillas que ganan',
                3 => 'Convierte aprobaciones en trabajos activos',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'El contratista que envía primero un presupuesto pulido suele ganar el trabajo. Hive te ayuda a ser ese contratista.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'sparkles',
              'title' => 'Redactado por IA',
              'body' => 'Detallado en minutos.',
            ),
            1 => array(
              'icon' => 'document-duplicate',
              'title' => 'Plantillas',
              'body' => 'Reutiliza lo que gana.',
            ),
            2 => array(
              'icon' => 'pencil',
              'title' => 'Editable',
              'body' => 'Ajusta cada partida.',
            ),
            3 => array(
              'icon' => 'swatch',
              'title' => 'Con tu marca',
              'body' => 'Se ve como tú.',
            ),
            4 => array(
              'icon' => 'pencil-square',
              'title' => 'Listo para firma electrónica',
              'body' => 'Aprueba en línea.',
            ),
            5 => array(
              'icon' => 'arrow-path',
              'title' => 'A trabajos',
              'body' => 'Las aprobaciones inician el trabajo.',
            ),
          ),
          'cta' => array(
            'heading' => 'Presupuesta más rápido y gana más trabajo.',
            'sub' => 'Deja que la IA redacte el presupuesto para que lo envíes primero.',
          ),
        ),
        'invoices' => array(
          'icon' => 'document-currency-dollar',
          'title' => 'Facturas',
          'body' => 'Envía facturas con tu marca y cobra por el trabajo terminado.',
          'hero' => 'Factura el trabajo y cobra más rápido',
          'lead' => 'Envía facturas limpias y con tu marca directamente desde el alcance aprobado—parciales o finales—para que el dinero entre sin idas y vueltas.',
          'rows' => array(
            0 => array(
              'heading' => 'Factura lo que acordaste',
              'text' => 'Factura directamente desde el presupuesto aprobado o las órdenes de cambio. Sin reescribir, sin disputas sobre lo incluido.',
              'points' => array(
                0 => 'Factura desde el alcance aprobado',
                1 => 'Facturación parcial o final',
                2 => 'Detallado y fácil de leer',
                3 => 'Vinculado al trabajo y tus libros',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Factura n.º 318',
                'rows' => array(
                  0 => array(
                    'label' => 'Parcial · obra bruta',
                    'value' => '$4,200',
                  ),
                  1 => array(
                    'label' => 'Materiales',
                    'value' => '$1,180',
                  ),
                  2 => array(
                    'label' => 'Importe a pagar',
                    'value' => '$5,380',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Claro para clientes, limpio para ti',
              'text' => 'Los clientes ven exactamente por qué pagan, con la fecha de vencimiento a la vista. Tú ves lo pendiente de un vistazo.',
              'points' => array(
                0 => 'Fechas de vencimiento claras que los clientes confían',
                1 => 'Rastrea lo que queda pendiente',
                2 => 'Conectado al costeo de obra',
                3 => 'Un registro de lo que está pagado',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una factura profesional vinculada al alcance acordado se paga más rápido y genera menos discusiones.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Con tu marca',
              'body' => 'Luce profesional.',
            ),
            1 => array(
              'icon' => 'arrow-path',
              'title' => 'Desde el alcance',
              'body' => 'Sin reescribir.',
            ),
            2 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Fechas de pago',
              'body' => 'Claras para clientes.',
            ),
            3 => array(
              'icon' => 'scale',
              'title' => 'Pendiente',
              'body' => 'Ve lo que se debe.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Vinculado al costeo',
              'body' => 'Ligado al trabajo.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Claridad de pagos',
              'body' => 'Sabe qué está saldado.',
            ),
          ),
          'cta' => array(
            'heading' => 'Cobra por el trabajo que terminaste.',
            'sub' => 'Facturas con tu marca desde el alcance que ya acordaste.',
          ),
        ),
        'e-signatures' => array(
          'icon' => 'pencil-square',
          'title' => 'Firmas electrónicas',
          'body' => 'Recoge firmas de clientes legalmente vinculantes desde cualquier dispositivo.',
          'hero' => 'Aprobación desde cualquier dispositivo, en segundos',
          'lead' => 'Recoge firmas legalmente vinculantes en presupuestos, órdenes de cambio y contratos desde cualquier teléfono o computadora—sin imprimir ni escanear.',
          'rows' => array(
            0 => array(
              'heading' => 'Aprobación sin papeleo',
              'text' => 'Envía un documento y tu cliente firma con un toque, esté donde esté. La aprobación se captura y se marca con fecha y hora al instante.',
              'points' => array(
                0 => 'Firma en cualquier dispositivo',
                1 => 'Legalmente vinculante y con fecha y hora',
                2 => 'Sin imprimir ni escanear',
                3 => 'Aprobación registrada al instante',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Firma · Presupuesto n.º 1042',
                'rows' => array(
                  0 => array(
                    'icon' => 'pencil-square',
                    'label' => 'Enviado al cliente',
                    'sub' => 'Lun 9:10 AM',
                  ),
                  1 => array(
                    'icon' => 'eye',
                    'label' => 'Abierto',
                    'sub' => 'Lun 9:14 AM',
                  ),
                  2 => array(
                    'icon' => 'check-badge',
                    'label' => 'Firmado',
                    'sub' => 'Lun 9:21 AM',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Protege cada acuerdo',
              'text' => 'Los documentos firmados se guardan junto al trabajo, así siempre hay prueba de qué se acordó y cuándo—sin el \'dijo, dije\'.',
              'points' => array(
                0 => 'Guardado junto al trabajo',
                1 => 'Prueba de lo que se acordó',
                2 => 'Fácil de recuperar después',
                3 => 'Mantiene a todos honestos',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una firma que puedes probar es la diferencia entre cobrar y asumir el costo.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'pencil-square',
              'title' => 'Firma electrónica',
              'body' => 'Toca para aprobar.',
            ),
            1 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Cualquier dispositivo',
              'body' => 'Teléfono o computadora.',
            ),
            2 => array(
              'icon' => 'shield-check',
              'title' => 'Vinculante',
              'body' => 'Legalmente válido.',
            ),
            3 => array(
              'icon' => 'clock',
              'title' => 'Con fecha y hora',
              'body' => 'Cuándo ocurrió.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'En el trabajo',
              'body' => 'Guardado con prueba.',
            ),
            5 => array(
              'icon' => 'eye',
              'title' => 'Seguimiento de apertura',
              'body' => 'Ve cuándo se abrió.',
            ),
          ),
          'cta' => array(
            'heading' => 'Consigue la aprobación sin papeleo.',
            'sub' => 'Firmas legalmente vinculantes desde cualquier dispositivo, guardadas con el trabajo.',
          ),
        ),
        'change-orders' => array(
          'icon' => 'arrows-right-left',
          'title' => 'Órdenes de cambio',
          'body' => 'Registra cambios de alcance y precio para no trabajar gratis.',
          'hero' => 'Cobra por cada cambio',
          'lead' => 'Registra los cambios de alcance y precio en cuanto surgen, consíguelos aprobados y asegúrate de que ningún trabajo extra quede sin cobrar.',
          'rows' => array(
            0 => array(
              'heading' => 'Documenta el cambio',
              'text' => 'Cuando el trabajo cambia, redacta una orden de cambio clara con el trabajo y costo añadidos. El cliente aprueba antes de que levantes una herramienta.',
              'points' => array(
                0 => 'Registra alcance y costo añadidos',
                1 => 'Aprobado antes de empezar el trabajo',
                2 => 'Registro claro de lo que cambió',
                3 => 'No más mejoras gratis',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Orden de cambio · Luces empotradas',
                'rows' => array(
                  0 => array(
                    'label' => '6 luces empotradas',
                    'value' => '+$1,250',
                  ),
                  1 => array(
                    'label' => 'Impacto en el calendario',
                    'value' => '+1 día',
                  ),
                  2 => array(
                    'label' => 'Estado',
                    'value' => 'Aprobado',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Fluye a la factura',
              'text' => 'Las órdenes de cambio aprobadas fluyen al trabajo y a la siguiente factura automáticamente, así el trabajo extra siempre aparece en la cuenta.',
              'points' => array(
                0 => 'Se suma al total del trabajo',
                1 => 'Facturado en la siguiente factura',
                2 => 'Protege tu margen',
                3 => 'Sin sorpresas al final',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'El trabajo que mata el margen es el cambio que nadie anotó. Hive se asegura de que quede anotado—y facturado.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Captura',
              'body' => 'Alcance y precio.',
            ),
            1 => array(
              'icon' => 'pencil-square',
              'title' => 'Aprobado',
              'body' => 'Firmado antes del trabajo.',
            ),
            2 => array(
              'icon' => 'document-text',
              'title' => 'Documentado',
              'body' => 'Registro claro.',
            ),
            3 => array(
              'icon' => 'banknotes',
              'title' => 'Facturado',
              'body' => 'En la siguiente factura.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Margen a salvo',
              'body' => 'Nada gratis.',
            ),
            5 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Calendario',
              'body' => 'Muestra el impacto en el tiempo.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja de hacer trabajo extra gratis.',
            'sub' => 'Captura cada cambio, apruébalo y factúralo.',
          ),
        ),
        'bids-proposals' => array(
          'icon' => 'clipboard-document-list',
          'title' => 'Presupuestos y propuestas',
          'body' => 'Sigue cada presupuesto desde el envío hasta la firma y da seguimiento a tiempo.',
          'hero' => 'Nunca dejes enfriar un presupuesto',
          'lead' => 'Sigue cada propuesta desde el envío hasta la firma, ve qué está pendiente y da seguimiento en el momento justo—para que más presupuestos se conviertan en trabajos.',
          'rows' => array(
            0 => array(
              'heading' => 'Todo tu embudo a la vista',
              'text' => 'Ve cada presupuesto que tienes fuera, dónde está y cuánto tiempo lleva parado. Los que necesitan un empujón saltan a la vista.',
              'points' => array(
                0 => 'Sigue presupuestos del envío a la firma',
                1 => 'Ve qué está pendiente',
                2 => 'Sabe cuáles necesitan seguimiento',
                3 => 'Mide tu tasa de éxito',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Presupuestos abiertos',
                'rows' => array(
                  0 => array(
                    'icon' => 'clipboard-document-list',
                    'label' => 'Remodelación Maple St',
                    'sub' => 'Enviado · hace 3 días',
                  ),
                  1 => array(
                    'icon' => 'eye',
                    'label' => 'Ampliación Oak Ave',
                    'sub' => 'Visto · dar seguimiento',
                  ),
                  2 => array(
                    'icon' => 'check-badge',
                    'label' => 'Terraza Pine Ct',
                    'sub' => 'Firmado',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Da seguimiento en el momento justo',
              'text' => 'Hive te avisa cuando una propuesta se queda callada, para que el trabajo que presupuestaste no se te escape al contratista que simplemente devolvió la llamada.',
              'points' => array(
                0 => 'Recordatorios de seguimiento a tiempo',
                1 => 'Ve cuándo se abrió un presupuesto',
                2 => 'Cierra más de lo que envías',
                3 => 'Deja de perder trabajo por el silencio',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'La mayoría de los presupuestos se pierden por el silencio, no por el precio. Dar seguimiento a tiempo gana trabajos que ya te habías ganado.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clipboard-document-list',
              'title' => 'Embudo',
              'body' => 'Cada presupuesto seguido.',
            ),
            1 => array(
              'icon' => 'eye',
              'title' => 'Seguimiento de aperturas',
              'body' => 'Ve cuándo se vio.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Seguimientos',
              'body' => 'Avisado a tiempo.',
            ),
            3 => array(
              'icon' => 'check-badge',
              'title' => 'Ganado',
              'body' => 'Firmado a trabajos.',
            ),
            4 => array(
              'icon' => 'chart-bar',
              'title' => 'Tasa de éxito',
              'body' => 'Mide el éxito.',
            ),
            5 => array(
              'icon' => 'arrow-path',
              'title' => 'A proyectos',
              'body' => 'Empieza el trabajo rápido.',
            ),
          ),
          'cta' => array(
            'heading' => 'Convierte más presupuestos en trabajos firmados.',
            'sub' => 'Sigue cada propuesta y da seguimiento antes de que se enfríe.',
          ),
        ),
        'lien-waivers' => array(
          'icon' => 'document-check',
          'title' => 'Renuncias de gravamen',
          'body' => 'Envía y recoge renuncias firmadas con enlaces seguros y sin cuenta.',
          'hero' => 'Renuncias de gravamen, firmadas y archivadas',
          'lead' => 'Envía renuncias y recoge firmas con enlaces seguros sin cuentas ni impresión—manteniendo siempre lista la documentación que protege el pago.',
          'rows' => array(
            0 => array(
              'heading' => 'Envía y firma en segundos',
              'text' => 'Genera la renuncia correcta, envía un enlace seguro y deja que tu subcontratista firme desde cualquier dispositivo. Regresa vinculada al trabajo y al pago.',
              'points' => array(
                0 => 'Renuncias condicionales e incondicionales',
                1 => 'Enlaces seguros y sin cuenta',
                2 => 'Firma desde cualquier teléfono',
                3 => 'Vinculadas al trabajo y al pago',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Renuncias · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Firmado',
                  ),
                  1 => array(
                    'icon' => 'document-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Firmado',
                  ),
                  2 => array(
                    'icon' => 'clock',
                    'label' => 'Summit Drywall',
                    'sub' => 'Pendiente',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Prueba cuando cuenta',
              'text' => 'Las renuncias firmadas se guardan junto al trabajo, así que cuando un contratista general o un banco las pide, la respuesta está a un clic.',
              'points' => array(
                0 => 'Guardadas junto al trabajo',
                1 => 'Un clic para presentarlas',
                2 => 'Rastrea quién falta',
                3 => 'Protege tu derecho al pago',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Las renuncias recogidas a tiempo mantienen los pagos fluyendo y los proyectos libres de gravámenes.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-check',
              'title' => 'Renuncia correcta',
              'body' => 'Condicional o no.',
            ),
            1 => array(
              'icon' => 'link',
              'title' => 'Enlaces seguros',
              'body' => 'Sin cuenta.',
            ),
            2 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Cualquier dispositivo',
              'body' => 'Firma desde el teléfono.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'En el trabajo',
              'body' => 'Guardadas con prueba.',
            ),
            4 => array(
              'icon' => 'clock',
              'title' => 'Rastrea el estado',
              'body' => 'Quién falta.',
            ),
            5 => array(
              'icon' => 'shield-check',
              'title' => 'Protegido',
              'body' => 'Pagos a salvo.',
            ),
          ),
          'cta' => array(
            'heading' => 'Ten cada renuncia firmada y archivada.',
            'sub' => 'Enlaces seguros que tus subcontratistas pueden firmar desde cualquier lugar.',
          ),
        ),
      ),
    ),
    'clients' => array(
      'label' => 'Prospectos y clientes',
      'eyebrow' => 'Prospectos y clientes',
      'grid_heading' => 'De la primera llamada al propietario satisfecho',
      'cards' => array(
        'lead-pipeline' => array(
          'icon' => 'magnifying-glass-plus',
          'title' => 'Embudo de leads',
          'body' => 'Capta y sigue las nuevas oportunidades para que ninguna se escape.',
          'hero' => 'Atrapa cada lead antes de que se te escape',
          'lead' => 'Capta nuevas oportunidades en un solo embudo, controla en qué punto está cada una y haz seguimiento a tiempo para que las llamadas que tanto te costó ganar se conviertan en trabajos.',
          'rows' => array(
            0 => array(
              'heading' => 'Un solo lugar para cada oportunidad',
              'text' => 'Las nuevas consultas llegan a tu embudo con los detalles que necesitas. Muévelas por las etapas para saber siempre qué está caliente y qué sigue.',
              'points' => array(
                0 => 'Capta leads de llamadas y formularios',
                1 => 'Sigue cada uno por etapas claras',
                2 => 'Añade notas, valor y próximos pasos',
                3 => 'Nada se pierde por el camino',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Embudo',
                'rows' => array(
                  0 => array(
                    'icon' => 'magnifying-glass-plus',
                    'label' => 'Reforma en Maple St',
                    'sub' => 'Nuevo · est. $48k',
                  ),
                  1 => array(
                    'icon' => 'phone',
                    'label' => 'Ampliación en Oak Ave',
                    'sub' => 'Contactado',
                  ),
                  2 => array(
                    'icon' => 'clipboard-document-list',
                    'label' => 'Terraza en Pine Ct',
                    'sub' => 'Oferta enviada',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Haz seguimiento y gana',
              'text' => 'Los recordatorios te mantienen al día con cada lead, para que contactes cuando el interés está alto, no una semana después de que ya contrataron a otro.',
              'points' => array(
                0 => 'Recordatorios de seguimiento puntuales',
                1 => 'Contacta mientras el interés está alto',
                2 => 'Ve tu conversión de un vistazo',
                3 => 'Gana más de lo que persigues',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Los leads se enfrían rápido. Un embudo que te recuerda convierte más primeras llamadas en trabajos firmados.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'magnifying-glass-plus',
              'title' => 'Captación',
              'body' => 'Cada oportunidad dentro.',
            ),
            1 => array(
              'icon' => 'view-columns',
              'title' => 'Etapas',
              'body' => 'Sigue cada paso.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Seguimientos',
              'body' => 'Recordado a tiempo.',
            ),
            3 => array(
              'icon' => 'pencil-square',
              'title' => 'Notas',
              'body' => 'Contexto en cada uno.',
            ),
            4 => array(
              'icon' => 'chart-bar',
              'title' => 'Conversión',
              'body' => 'Ve tu tasa de éxito.',
            ),
            5 => array(
              'icon' => 'arrow-path',
              'title' => 'A clientes',
              'body' => 'Ganado en un clic.',
            ),
          ),
          'cta' => array(
            'heading' => 'Convierte más primeras llamadas en trabajos.',
            'sub' => 'Capta cada lead y haz seguimiento antes de que se enfríe.',
          ),
        ),
        'lead-to-client' => array(
          'icon' => 'arrow-path',
          'title' => 'De lead a cliente',
          'body' => 'Convierte leads ganados en clientes y proyectos con un clic.',
          'hero' => '¿Ganaste el trabajo? Empiézalo en un clic',
          'lead' => 'Convierte un lead ganado en un cliente y un proyecto activo al instante, arrastrando el contacto, las notas y el presupuesto para no reescribir nada.',
          'rows' => array(
            0 => array(
              'heading' => 'Sin reescribir, sin perder contexto',
              'text' => 'Cuando un lead dice que sí, conviértelo en cliente y proyecto con un clic. Sus datos, su historial y su presupuesto van con él automáticamente.',
              'points' => array(
                0 => 'Convierte el lead en cliente al instante',
                1 => 'Crea el proyecto al mismo tiempo',
                2 => 'Arrastra contacto, notas y presupuesto',
                3 => 'Cero entrada de datos duplicada',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Conversión · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'user-plus',
                    'label' => 'Cliente creado',
                    'sub' => 'Los Henderson',
                  ),
                  1 => array(
                    'icon' => 'folder-plus',
                    'label' => 'Proyecto iniciado',
                    'sub' => 'Reforma de cocina',
                  ),
                  2 => array(
                    'icon' => 'document-text',
                    'label' => 'Presupuesto adjunto',
                    'sub' => '$48,000',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Arranca a toda marcha',
              'text' => 'El nuevo proyecto está listo para planificar, costear y comunicar al cliente desde el primer minuto, así empiezas con fuerza en vez de configurar.',
              'points' => array(
                0 => 'Proyecto listo para planificar',
                1 => 'El costeo empieza de inmediato',
                2 => 'Portal del cliente disponible',
                3 => 'Un inicio fuerte y ordenado',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'El momento en que un cliente dice que sí es el momento de organizarse, no de empezar a meter datos.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrow-path',
              'title' => 'Un clic',
              'body' => 'De lead a cliente.',
            ),
            1 => array(
              'icon' => 'folder-plus',
              'title' => 'Proyecto',
              'body' => 'Creado al instante.',
            ),
            2 => array(
              'icon' => 'document-text',
              'title' => 'Presupuesto',
              'body' => 'Va con el lead.',
            ),
            3 => array(
              'icon' => 'clipboard',
              'title' => 'Historial guardado',
              'body' => 'Todas las notas se conservan.',
            ),
            4 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Listo para planificar',
              'body' => 'Empieza a planificar.',
            ),
            5 => array(
              'icon' => 'computer-desktop',
              'title' => 'Portal activado',
              'body' => 'El cliente puede verlo.',
            ),
          ),
          'cta' => array(
            'heading' => 'Empieza el trabajo en cuanto dicen que sí.',
            'sub' => 'Convierte un lead en cliente y proyecto con un clic.',
          ),
        ),
        'client-directory' => array(
          'icon' => 'identification',
          'title' => 'Directorio de clientes',
          'body' => 'Cada propietario con su historial completo de trabajos y contacto.',
          'hero' => 'Cada cliente y todo su historial',
          'lead' => 'Ten a cada propietario en un solo directorio con sus datos de contacto, proyectos, pagos y conversaciones, para tener siempre el panorama completo.',
          'rows' => array(
            0 => array(
              'heading' => 'El panorama completo, en un solo lugar',
              'text' => 'Abre un cliente y ve cada trabajo que le has hecho, lo que ha pagado y todo tu historial de conversaciones. Se acabó buscar entre apps.',
              'points' => array(
                0 => 'Todos los proyectos por cliente',
                1 => 'Historial de pagos y saldos',
                2 => 'Hilo completo de conversaciones',
                3 => 'Datos de contacto siempre al día',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Los Henderson',
                'rows' => array(
                  0 => array(
                    'icon' => 'folder',
                    'label' => 'Reforma de cocina',
                    'sub' => 'En curso',
                  ),
                  1 => array(
                    'icon' => 'folder',
                    'label' => 'Baño · 2024',
                    'sub' => 'Completado',
                  ),
                  2 => array(
                    'icon' => 'banknotes',
                    'label' => 'Facturado histórico',
                    'sub' => '$71,500',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Trabajos recurrentes sin esfuerzo',
              'text' => 'Cuando un cliente antiguo vuelve a llamar, ya conoces su casa, sus preferencias y su historial, así el próximo trabajo empieza con buen pie.',
              'points' => array(
                0 => 'Reconoce clientes que vuelven al instante',
                1 => 'Consulta trabajos y notas anteriores',
                2 => 'Personaliza cada interacción',
                3 => 'Gana más trabajos recurrentes',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Tus mejores leads son los clientes antiguos. Conocer su historial hace que el próximo trabajo sea más fácil de ganar y ejecutar.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'identification',
              'title' => 'Directorio',
              'body' => 'Cada cliente en un solo lugar.',
            ),
            1 => array(
              'icon' => 'folder',
              'title' => 'Todos los proyectos',
              'body' => 'Pasados y presentes.',
            ),
            2 => array(
              'icon' => 'banknotes',
              'title' => 'Pagos',
              'body' => 'Historial completo de pagos.',
            ),
            3 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'Conversaciones',
              'body' => 'Cada hilo.',
            ),
            4 => array(
              'icon' => 'phone',
              'title' => 'Contactos',
              'body' => 'Siempre al día.',
            ),
            5 => array(
              'icon' => 'arrow-path',
              'title' => 'Trabajo recurrente',
              'body' => 'Empieza rápido.',
            ),
          ),
          'cta' => array(
            'heading' => 'Ten a cada cliente a tu alcance.',
            'sub' => 'Historial completo de trabajos, pagos y conversaciones en un directorio.',
          ),
        ),
        'homeowner-portal' => array(
          'icon' => 'computer-desktop',
          'title' => 'Portal del propietario',
          'body' => 'Una ventana en tiempo real al proyecto que los clientes pueden consultar cuando quieran.',
          'hero' => 'Dales a los clientes una ventana a su proyecto',
          'lead' => 'Un portal privado y en tiempo real permite a los propietarios ver estado, calendario, fotos, documentos y pagos en cualquier momento, así te llaman menos y confían más en ti.',
          'rows' => array(
            0 => array(
              'heading' => 'Siempre al tanto',
              'text' => 'Los clientes abren un enlace seguro y ven exactamente en qué punto está su proyecto. Menos mensajes de "¿alguna novedad?" y más confianza en ti.',
              'points' => array(
                0 => 'Estado y calendario en vivo',
                1 => 'Fotos de obra y avances',
                2 => 'Documentos y pagos',
                3 => 'Seguro, sin necesidad de app',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Vista del cliente · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'eye',
                    'label' => 'Estado',
                    'sub' => '62% · eléctrica',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Próxima visita',
                    'sub' => 'mar 30/6',
                  ),
                  2 => array(
                    'icon' => 'photo',
                    'label' => 'Fotos nuevas',
                    'sub' => '4 añadidas',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Menos acompañamiento constante',
              'text' => 'Cuando los clientes resuelven sus propias dudas, pasas menos tiempo al teléfono y más construyendo. Las actualizaciones fluyen sin esfuerzo extra.',
              'points' => array(
                0 => 'Menos llamadas y mensajes por el estado',
                1 => 'Las actualizaciones se envían solas',
                2 => 'Te distingue de la competencia',
                3 => 'Clientes más contentos y tranquilos',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un cliente que ve el avance es un cliente que confía en ti, y que interrumpe tu día mucho menos.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'eye',
              'title' => 'Estado en vivo',
              'body' => 'Siempre al día.',
            ),
            1 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Calendario',
              'body' => 'Qué sigue.',
            ),
            2 => array(
              'icon' => 'photo',
              'title' => 'Fotos',
              'body' => 'Fotos de avance.',
            ),
            3 => array(
              'icon' => 'pencil-square',
              'title' => 'Documentos',
              'body' => 'Revisa y firma.',
            ),
            4 => array(
              'icon' => 'banknotes',
              'title' => 'Pagos',
              'body' => 'Consulta saldos.',
            ),
            5 => array(
              'icon' => 'finger-print',
              'title' => 'Seguro',
              'body' => 'Enlace privado.',
            ),
          ),
          'cta' => array(
            'heading' => 'Ofrece a tus clientes un portal que les encantará.',
            'sub' => 'Acceso al proyecto en tiempo real que reduce las llamadas por el estado.',
          ),
        ),
        'schedule-sharing' => array(
          'icon' => 'paper-airplane',
          'title' => 'Compartir el calendario',
          'body' => 'Envía actualizaciones de "qué sigue" en vivo sin mover un dedo.',
          'hero' => 'Comparte qué sigue, de forma automática',
          'lead' => 'Envía a tus clientes un enlace de calendario en vivo que siempre muestra la próxima visita y el próximo hito, así se mantienen informados sin que envíes ni una sola actualización.',
          'rows' => array(
            0 => array(
              'heading' => 'Un enlace en vivo, no una llamada',
              'text' => 'Los clientes reciben un calendario que se actualiza solo. Cuando una fecha cambia, su vista también, sin correos nuevos ni llamadas incómodas.',
              'points' => array(
                0 => '"Qué sigue" en vivo para los clientes',
                1 => 'Se actualiza al cambiar las fechas',
                2 => 'Sin mensajes de actualización manuales',
                3 => 'Funciona en cualquier dispositivo',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Calendario del cliente',
                'rows' => array(
                  0 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Eléctrica en bruto',
                    'sub' => 'mar 30/6',
                  ),
                  1 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Inspección',
                    'sub' => 'jue 2/7',
                  ),
                  2 => array(
                    'icon' => 'swatch',
                    'label' => 'Inicio de acabados',
                    'sub' => 'lun 6/7',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Menos sorpresas, menos llamadas',
              'text' => 'Cuando los clientes ven el plan, dejan de preguntar y empiezan a confiar. Los cambios se comunican en el instante en que ocurren.',
              'points' => array(
                0 => 'Los clientes siempre conocen el plan',
                1 => 'Los cambios se comunican al instante',
                2 => 'Menos llamadas de "¿cuándo vienen?"',
                3 => 'Una experiencia más profesional',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'La mayor parte de la frustración del cliente es simplemente no saber. Un calendario en vivo lo resuelve sin sumar a tu día.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'paper-airplane',
              'title' => 'Enlace en vivo',
              'body' => 'Siempre al día.',
            ),
            1 => array(
              'icon' => 'bolt',
              'title' => 'Se actualiza solo',
              'body' => 'Cuando cambian las fechas.',
            ),
            2 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Qué sigue',
              'body' => 'Próximas visitas.',
            ),
            3 => array(
              'icon' => 'flag',
              'title' => 'Hitos',
              'body' => 'Momentos clave a la vista.',
            ),
            4 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Cualquier dispositivo',
              'body' => 'Sin app.',
            ),
            5 => array(
              'icon' => 'face-smile',
              'title' => 'Menos llamadas',
              'body' => 'Los clientes se autogestionan.',
            ),
          ),
          'cta' => array(
            'heading' => 'Mantén informados a los clientes en piloto automático.',
            'sub' => 'Un enlace de calendario en vivo que se actualiza solo.',
          ),
        ),
        'contact-sync' => array(
          'icon' => 'at-symbol',
          'title' => 'Sincronización de contactos',
          'body' => 'Los contactos entran desde tu correo para que los registros estén al día.',
          'hero' => 'Contactos que se mantienen al día solos',
          'lead' => 'Hive extrae los contactos de tu correo para que los registros de clientes y proveedores estén al día sin que mantengas una agenda aparte.',
          'rows' => array(
            0 => array(
              'heading' => 'Se acabó la doble captura',
              'text' => 'Las personas nuevas a las que escribes aparecen en Hive con sus datos, listas para vincular a un lead, cliente o proveedor. Tus registros se construyen solos.',
              'points' => array(
                0 => 'Los contactos entran desde el correo',
                1 => 'Vincula a leads, clientes o proveedores',
                2 => 'Los datos se mantienen al día solos',
                3 => 'Sin una agenda aparte que mantener',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Contactos sincronizados',
                'rows' => array(
                  0 => array(
                    'icon' => 'at-symbol',
                    'label' => 'J. Henderson',
                    'sub' => 'Cliente · Maple St',
                  ),
                  1 => array(
                    'icon' => 'at-symbol',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Proveedor',
                  ),
                  2 => array(
                    'icon' => 'at-symbol',
                    'label' => 'Inspector municipal',
                    'sub' => 'Contacto',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Todos conectados con el trabajo',
              'text' => 'Como los contactos se vinculan a los trabajos y las conversaciones, siempre sabes cómo encaja cada persona, y la localizas con un toque.',
              'points' => array(
                0 => 'Vinculados a trabajos e hilos',
                1 => 'Contacta a cualquiera con un toque',
                2 => 'Los registros se mantienen exactos',
                3 => 'Menos papeleo, más construir',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'La agenda que nunca tienes que mantener es la que de verdad está al día cuando la necesitas.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'at-symbol',
              'title' => 'Sincronización de correo',
              'body' => 'Los contactos entran.',
            ),
            1 => array(
              'icon' => 'user-plus',
              'title' => 'Añadido automático',
              'body' => 'Personas nuevas captadas.',
            ),
            2 => array(
              'icon' => 'arrow-path',
              'title' => 'Al día',
              'body' => 'Datos siempre frescos.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Vinculados',
              'body' => 'Ligados a los trabajos.',
            ),
            4 => array(
              'icon' => 'phone',
              'title' => 'Contacto con un toque',
              'body' => 'Llama o escribe al instante.',
            ),
            5 => array(
              'icon' => 'sparkles',
              'title' => 'Menos papeleo',
              'body' => 'Sin captura manual.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja de mantener una agenda.',
            'sub' => 'Deja que los contactos se sincronicen y se mantengan al día solos.',
          ),
        ),
      ),
    ),
    'vendors' => array(
      'label' => 'Proveedores y cumplimiento',
      'eyebrow' => 'Proveedores y cumplimiento',
      'grid_heading' => 'Ten a tus subcontratistas al día y cubiertos',
      'cards' => array(
        'vendor-directory' => array(
          'icon' => 'user-group',
          'title' => 'Directorio de proveedores',
          'body' => 'Cada subcontratista y proveedor con oficio, tarifas e historial de trabajos.',
          'hero' => 'Cada subcontratista y proveedor al alcance de la mano',
          'lead' => 'Ten a cada proveedor en un solo directorio con su oficio, tarifas, contacto e historial de trabajos, para que siempre sepas a quién llamar y cuánto cuesta.',
          'rows' => array(
            0 => array(
              'heading' => 'Todo tu equipo, organizado',
              'text' => 'Guarda cada subcontratista y proveedor con los datos que importan: oficio, tarifas habituales, contactos y cada trabajo que ha hecho para ti.',
              'points' => array(
                0 => 'Oficio, tarifas y contactos',
                1 => 'Historial completo de trabajos por proveedor',
                2 => 'Notas sobre calidad y fiabilidad',
                3 => 'Encuentra al subcontratista adecuado rápido',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Subcontratistas · Fontanería',
                'rows' => array(
                  0 => array(
                    'icon' => 'user-group',
                    'label' => 'Rivera Plumbing',
                    'sub' => '12 trabajos · $42/h',
                  ),
                  1 => array(
                    'icon' => 'user-group',
                    'label' => 'Apex Mechanical',
                    'sub' => '5 trabajos',
                  ),
                  2 => array(
                    'icon' => 'user-group',
                    'label' => 'BlueLine Plumbing',
                    'sub' => '2 trabajos',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Conectado con todo',
              'text' => 'Cada proveedor se enlaza con sus pagos, seguros y los trabajos en los que está, así que toda la relación queda a un clic.',
              'points' => array(
                0 => 'Enlazado a pagos y saldos',
                1 => 'Vinculado a COIs y coberturas',
                2 => 'Ve trabajos actuales y pasados',
                3 => 'Contáctalos con un toque',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Saber exactamente a quién llamar —y cuánto cuesta— convierte armar el equipo de un trabajo en una tarea de dos minutos.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'user-group',
              'title' => 'Directorio',
              'body' => 'Cada proveedor en un solo lugar.',
            ),
            1 => array(
              'icon' => 'wrench',
              'title' => 'Oficio y tarifas',
              'body' => 'Sabe quién y cuánto.',
            ),
            2 => array(
              'icon' => 'folder',
              'title' => 'Historial de trabajos',
              'body' => 'Todo lo que han trabajado.',
            ),
            3 => array(
              'icon' => 'wallet',
              'title' => 'Pagos',
              'body' => 'Saldos enlazados.',
            ),
            4 => array(
              'icon' => 'shield-check',
              'title' => 'Cumplimiento',
              'body' => 'COIs adjuntos.',
            ),
            5 => array(
              'icon' => 'phone',
              'title' => 'Contacto en un toque',
              'body' => 'Llama o envía SMS rápido.',
            ),
          ),
          'cta' => array(
            'heading' => 'Sabe exactamente a quién llamar.',
            'sub' => 'Cada subcontratista y proveedor con tarifas, historial y cobertura.',
          ),
        ),
        'vendor-payments' => array(
          'icon' => 'wallet',
          'title' => 'Pagos a proveedores',
          'body' => 'Paga a los subcontratistas y mantén cada pago ligado al trabajo correcto.',
          'hero' => 'Paga a tus subcontratistas y mantén el trabajo en orden',
          'lead' => 'Registra y sigue los pagos a cada subcontratista y proveedor con cada dólar ligado al trabajo y saldo correcto, para que el costo de mano de obra siempre caiga donde corresponde.',
          'rows' => array(
            0 => array(
              'heading' => 'En el trabajo correcto, siempre',
              'text' => 'Cuando pagas a un subcontratista, el costo se adjunta al proyecto automáticamente y el saldo del proveedor se actualiza. Se acabó adivinar qué trabajo cubrió un pago.',
              'points' => array(
                0 => 'Paga a subcontratistas y proveedores fácil',
                1 => 'El costo cae en el trabajo correcto',
                2 => 'Saldo activo por proveedor',
                3 => 'Registros limpios para los 1099',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Rivera Plumbing',
                'rows' => array(
                  0 => array(
                    'label' => 'Facturado',
                    'value' => '$6,400',
                  ),
                  1 => array(
                    'label' => 'Pagado',
                    'value' => '$4,000',
                  ),
                  2 => array(
                    'label' => 'Saldo',
                    'value' => '$2,400',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Paga a los subcontratistas que te mantienen cubierto',
              'text' => 'Los pagos se conectan con el seguro y la compensación de cada proveedor, para que detectes documentos vencidos antes de emitir el próximo cheque.',
              'points' => array(
                0 => 'Enlazado a COIs y compensación',
                1 => 'Marca primero los papeles vencidos',
                2 => 'Alimenta el costeo de trabajos',
                3 => 'Cuadra con tu movimiento bancario',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Pagar a los subcontratistas por Hive mantiene el costo, los saldos y el cumplimiento en un solo lugar, sin hoja de cálculo aparte.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'wallet',
              'title' => 'Paga a subcontratistas',
              'body' => 'Desde un solo lugar.',
            ),
            1 => array(
              'icon' => 'folder',
              'title' => 'Por trabajo',
              'body' => 'Costo en el proyecto.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Saldos',
              'body' => 'Por proveedor.',
            ),
            3 => array(
              'icon' => 'shield-check',
              'title' => 'Cumplimiento',
              'body' => 'COIs enlazados.',
            ),
            4 => array(
              'icon' => 'document-text',
              'title' => 'Listo para 1099',
              'body' => 'Cierre de año limpio.',
            ),
            5 => array(
              'icon' => 'calculator',
              'title' => 'Alimenta el costeo',
              'body' => 'Mano de obra rastreada.',
            ),
          ),
          'cta' => array(
            'heading' => 'Paga a tus subcontratistas sin perder el hilo.',
            'sub' => 'Cada pago ligado al trabajo, el saldo y la cobertura.',
          ),
        ),
        'coi-tracking' => array(
          'icon' => 'shield-check',
          'title' => 'Seguimiento de COI',
          'body' => 'Guarda los certificados de seguro y vigila las fechas de vencimiento.',
          'hero' => 'Que ningún certificado se te escape',
          'lead' => 'Guarda cada certificado de seguro, vincúlalo al proveedor y al trabajo, y recibe alertas antes de que venzan, para que nunca quedes expuesto.',
          'rows' => array(
            0 => array(
              'heading' => 'Cada COI archivado',
              'text' => 'Mantén los certificados organizados por proveedor y conectados a los trabajos en que participan. La prueba de cobertura está siempre a una búsqueda.',
              'points' => array(
                0 => 'Guarda COIs por proveedor',
                1 => 'Ligados a los trabajos en que participan',
                2 => 'Ve el estado de cobertura de un vistazo',
                3 => 'Pide actualizaciones a los agentes rápido',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Estado de cobertura',
                'rows' => array(
                  0 => array(
                    'icon' => 'shield-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Válido hasta 30/11',
                  ),
                  1 => array(
                    'icon' => 'shield-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Válido hasta 15/9',
                  ),
                  2 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'Vence en 9 días',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Alertas antes de que caduque',
              'text' => 'Hive vigila las fechas de vencimiento y te avisa con antelación, para que reúnas un certificado renovado antes de que un subcontratista trabaje sin cobertura.',
              'points' => array(
                0 => 'Alertas de vencimiento automáticas',
                1 => 'Detecta caducidades antes de que ocurran',
                2 => 'Reduce tu responsabilidad',
                3 => 'Cumple con contratistas generales y prestamistas',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un COI vencido que se te pasó es un reclamo esperando caerte encima. Hive se asegura de que nunca se te escape.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'shield-check',
              'title' => 'COIs guardados',
              'body' => 'Cada certificado.',
            ),
            1 => array(
              'icon' => 'user-group',
              'title' => 'Por proveedor',
              'body' => 'Organizado por subcontratista.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Alertas de vencimiento',
              'body' => 'Avisado con antelación.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Por trabajo',
              'body' => 'Vinculado a proyectos.',
            ),
            4 => array(
              'icon' => 'envelope',
              'title' => 'Solicitar',
              'body' => 'Pide a los agentes rápido.',
            ),
            5 => array(
              'icon' => 'scale',
              'title' => 'Menos responsabilidad',
              'body' => 'Nunca sin cobertura.',
            ),
          ),
          'cta' => array(
            'heading' => 'Mantén cada COI vigente.',
            'sub' => 'Guarda certificados y recibe alertas antes de que venzan.',
          ),
        ),
        'workers-comp' => array(
          'icon' => 'clipboard-document-check',
          'title' => 'Compensación laboral',
          'body' => 'Verifica la cobertura y recibe alertas antes de que caduque.',
          'hero' => 'Asegúrate de que cada subcontratista esté cubierto',
          'lead' => 'Verifica la compensación laboral de cada subcontratista, guarda la prueba y recibe avisos antes de que caduque cualquier póliza, para que una lesión nunca se vuelva tu responsabilidad.',
          'rows' => array(
            0 => array(
              'heading' => 'Verifica antes de que pisen la obra',
              'text' => 'Confirma la cobertura por adelantado y mantén la prueba ligada al proveedor y al trabajo. Cualquiera sin cobertura queda marcado antes de trabajar.',
              'points' => array(
                0 => 'Verifica el seguro de cada subcontratista',
                1 => 'Comprobantes guardados por proveedor',
                2 => 'Vinculados a las obras que trabajan',
                3 => 'Marca a los no cubiertos',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Comp. de trabajadores',
                'rows' => array(
                  0 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Activo',
                  ),
                  1 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Activo',
                  ),
                  2 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'Vence 15/7',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Protegido del reclamo que no viste venir',
              'text' => 'Las alertas anticipadas de vencimiento evitan que tengas una cuadrilla sin seguro en tu obra, dejándote fuera de riesgo si algo sale mal.',
              'points' => array(
                0 => 'Alertas anticipadas de vencimiento',
                1 => 'Protección ante reclamos',
                2 => 'Listo para auditoría',
                3 => 'Tranquilidad en la obra',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una sola lesión sin seguro puede hundir a un contratista pequeño. Hive mantiene la cobertura vigente para que nunca ocurra.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Verificado',
              'body' => 'Cobertura confirmada.',
            ),
            1 => array(
              'icon' => 'user-group',
              'title' => 'Por subcontratista',
              'body' => 'Comprobante por proveedor.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Alertas de vencimiento',
              'body' => 'Aviso anticipado.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Por obra',
              'body' => 'Vinculado a obras.',
            ),
            4 => array(
              'icon' => 'shield-check',
              'title' => 'Protegido',
              'body' => 'Reclamos cubiertos.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Listo para auditoría',
              'body' => 'Comprobantes a mano.',
            ),
          ),
          'cta' => array(
            'heading' => 'Mantén vigente la comp. de trabajadores.',
            'sub' => 'Verifica la cobertura y recibe alertas antes de que venza.',
          ),
        ),
        'insurance-agents' => array(
          'icon' => 'building-office-2',
          'title' => 'Agentes de seguros',
          'body' => 'Ten a mano los contactos de los agentes para pedir certificados rápido.',
          'hero' => 'Consigue certificados sin dar tantas vueltas',
          'lead' => 'Guarda el agente de seguros de cada proveedor para que un certificado nuevo o una verificación de cobertura sean una consulta rápida, no una semana de llamadas sin respuesta.',
          'rows' => array(
            0 => array(
              'heading' => 'El agente correcto, a mano',
              'text' => 'Guarda la agencia y el agente detrás de la cobertura de cada proveedor. Cuando necesites un COI actualizado, sabes exactamente a quién pedirlo.',
              'points' => array(
                0 => 'Contactos de agentes por proveedor',
                1 => 'Pide actualizaciones con un toque',
                2 => 'Sin buscar a quién llamar',
                3 => 'COIs más rápidos',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Agentes',
                'rows' => array(
                  0 => array(
                    'icon' => 'building-office-2',
                    'label' => 'Coast Insurance',
                    'sub' => 'Rivera Plumbing',
                  ),
                  1 => array(
                    'icon' => 'building-office-2',
                    'label' => 'Summit Agency',
                    'sub' => 'Apex Electric',
                  ),
                  2 => array(
                    'icon' => 'building-office-2',
                    'label' => 'Harbor Group',
                    'sub' => 'Summit Drywall',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Renovaciones sin demoras',
              'text' => 'Cuando un certificado esté por vencer, contacta al agente directamente desde Hive y mantén tu obra cubierta sin perder días.',
              'points' => array(
                0 => 'Contacta agentes desde Hive',
                1 => 'Vincula solicitudes al proveedor',
                2 => 'Mantén las obras cubiertas',
                3 => 'Sin costosas brechas de cobertura',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'La forma más rápida de arreglar un COI por vencer es saber exactamente a qué agente escribir; Hive lo mantiene a un toque de distancia.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'building-office-2',
              'title' => 'Agentes registrados',
              'body' => 'Por proveedor.',
            ),
            1 => array(
              'icon' => 'envelope',
              'title' => 'Solicitud rápida',
              'body' => 'Pide con un toque.',
            ),
            2 => array(
              'icon' => 'shield-check',
              'title' => 'Ligado a cobertura',
              'body' => 'Vinculado a COIs.',
            ),
            3 => array(
              'icon' => 'clock',
              'title' => 'COIs más rápidos',
              'body' => 'Sin llamadas perdidas.',
            ),
            4 => array(
              'icon' => 'user-group',
              'title' => 'Por proveedor',
              'body' => 'Sabe a quién pedir.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Sin brechas',
              'body' => 'Mantente cubierto.',
            ),
          ),
          'cta' => array(
            'heading' => 'Consigue certificados sin dar tantas vueltas.',
            'sub' => 'El agente de cada proveedor registrado para solicitudes rápidas.',
          ),
        ),
        'document-audits' => array(
          'icon' => 'document-magnifying-glass',
          'title' => 'Auditorías de documentos',
          'body' => 'Comprobaciones automáticas para que el papeleo faltante salga a la luz a tiempo.',
          'hero' => 'Detecta el papeleo faltante antes de que te perjudique',
          'lead' => 'Las auditorías automáticas revisan tus proveedores y obras en busca de documentos faltantes o por vencer, para que las brechas salgan a la luz a tiempo, no cuando el contratista general o el inspector las pidan.',
          'rows' => array(
            0 => array(
              'heading' => 'Un control permanente de tus archivos',
              'text' => 'Hive comprueba continuamente los COIs faltantes, la cobertura vencida, las renuncias sin firmar y los registros de proveedores incompletos, y luego te muestra exactamente qué falla.',
              'points' => array(
                0 => 'Escanea documentos faltantes',
                1 => 'Marca cobertura por vencer',
                2 => 'Detecta renuncias sin firmar',
                3 => 'Ve brechas por proveedor y obra',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Auditoría · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'COI por vencer',
                  ),
                  1 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Apex Electric',
                    'sub' => 'Renuncia sin firmar',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Todo en regla',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Siempre listo para la inspección',
              'text' => 'Cuando el contratista general, el prestamista o el inspector pidan papeleo, estás listo, porque Hive ya te dijo qué faltaba y lo resolviste.',
              'points' => array(
                0 => 'Cubre las brechas antes de que pregunten',
                1 => 'Listo para auditoría e inspección',
                2 => 'Reduce el riesgo de incumplimiento',
                3 => 'Protege tu reputación',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'El papeleo faltante hallado a tiempo es un correo rápido. Hallado durante una auditoría, puede frenar una obra en seco.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-magnifying-glass',
              'title' => 'Auto-auditoría',
              'body' => 'Controles permanentes.',
            ),
            1 => array(
              'icon' => 'shield-check',
              'title' => 'COIs faltantes',
              'body' => 'Detectados a tiempo.',
            ),
            2 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Cobertura vencida',
              'body' => 'Marcada rápido.',
            ),
            3 => array(
              'icon' => 'document-check',
              'title' => 'Renuncias sin firmar',
              'body' => 'Detectadas a tiempo.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Por obra',
              'body' => 'Brechas por obra.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Listo para auditoría',
              'body' => 'Siempre preparado.',
            ),
          ),
          'cta' => array(
            'heading' => 'Encuentra la brecha antes que el auditor.',
            'sub' => 'Las comprobaciones automáticas revelan el papeleo faltante a tiempo.',
          ),
        ),
      ),
    ),
    'planning' => array(
      'label' => 'Planificación',
      'eyebrow' => 'Proyectos y planificación',
      'grid_heading' => 'Planifica el trabajo y trabaja el plan',
      'cards' => array(
        'gantt' => array(
          'icon' => 'calendar-date-range',
          'title' => 'Cronograma Gantt',
          'body' => 'Programación de arrastrar y soltar con dependencias en todas las obras.',
          'hero' => 'Ve cada obra en una sola línea de tiempo',
          'lead' => 'La programación de arrastrar y soltar con dependencias te permite planificar cuadrillas en todas las obras a la vez, para que dejes de sobrecargar y empieces a terminar a tiempo.',
          'rows' => array(
            0 => array(
              'heading' => 'Programa arrastrando',
              'text' => 'Distribuye las tareas en una línea de tiempo visual, define qué depende de qué y mueve fechas arrastrando. Todo el plan se ajusta al cambio.',
              'points' => array(
                0 => 'Programación de tareas con arrastrar y soltar',
                1 => 'Dependencias que se ajustan solas',
                2 => 'Ve todos los trabajos a la vez',
                3 => 'Detecta conflictos antes de que ocurran',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Cronograma · Esta semana',
                'rows' => array(
                  0 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Demolición · Maple St',
                    'sub' => 'Lun–mar',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Instalación básica · Oak Ave',
                    'sub' => 'Mié–vie',
                  ),
                  2 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Inspección · Pine Ct',
                    'sub' => 'Jue',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Termina los trabajos a tiempo',
              'text' => 'Cuando una fecha se retrasa, las dependencias se mueven con ella y ves el efecto al instante, para reaccionar antes de que el retraso se dispare.',
              'points' => array(
                0 => 'Los retrasos se propagan a la vista',
                1 => 'Reacciona antes de que se disparen',
                2 => 'Mantén a las cuadrillas siempre ocupadas',
                3 => 'Cumple tus fechas de entrega',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un cronograma que muestra el efecto de cada retraso es como los pequeños contratistas mantienen varios trabajos al día a la vez.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Cronograma',
              'body' => 'Todos los trabajos a la vez.',
            ),
            1 => array(
              'icon' => 'arrows-pointing-out',
              'title' => 'Arrastrar y soltar',
              'body' => 'Reprograma rápido.',
            ),
            2 => array(
              'icon' => 'link',
              'title' => 'Dependencias',
              'body' => 'Ajustan fechas solas.',
            ),
            3 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Conflictos',
              'body' => 'Detectados a tiempo.',
            ),
            4 => array(
              'icon' => 'user-group',
              'title' => 'Vista de cuadrilla',
              'body' => 'Quién está dónde.',
            ),
            5 => array(
              'icon' => 'flag',
              'title' => 'Hitos',
              'body' => 'Sigue fechas clave.',
            ),
          ),
          'cta' => array(
            'heading' => 'Mantén cada trabajo en su cronograma.',
            'sub' => 'Un solo cronograma de arrastrar y soltar para todos tus proyectos.',
          ),
        ),
        'kanban' => array(
          'icon' => 'view-columns',
          'title' => 'Tablero kanban',
          'body' => 'Mueve el trabajo por etapas en un tablero que toda la cuadrilla entiende.',
          'hero' => 'Mueve el trabajo en un tablero que todos entienden',
          'lead' => 'Un tablero simple mueve las tareas por etapas que toda la cuadrilla entiende de un vistazo, para que todos sepan qué sigue sin una reunión.',
          'rows' => array(
            0 => array(
              'heading' => 'Etapas que cualquiera sigue',
              'text' => 'Arrastra tarjetas de por hacer a en curso a hecho. El tablero deja claro el estado del trabajo tanto en la oficina como en el campo.',
              'points' => array(
                0 => 'Etapas visuales para cada tarea',
                1 => 'Arrastra tarjetas según avanza el trabajo',
                2 => 'Asigna responsables y fechas límite',
                3 => 'Claro para oficina y campo',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Tablero · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'view-columns',
                    'label' => 'Por hacer',
                    'sub' => 'Azulejos, pintura',
                  ),
                  1 => array(
                    'icon' => 'view-columns',
                    'label' => 'En curso',
                    'sub' => 'Electricidad',
                  ),
                  2 => array(
                    'icon' => 'view-columns',
                    'label' => 'Hecho',
                    'sub' => 'Demolición, plomería',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Menos perseguir el estado',
              'text' => 'Cuando el tablero es la fuente de verdad, nadie tiene que preguntar cómo van las cosas. Las actualizaciones ocurren según avanza el trabajo, no en una reunión.',
              'points' => array(
                0 => 'Una sola fuente de verdad',
                1 => 'Menos reuniones de estado',
                2 => 'Todos alineados',
                3 => 'Nada se olvida',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un tablero que la cuadrilla de verdad entiende reemplaza una docena de mensajes de "¿dónde vamos?" al día.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'view-columns',
              'title' => 'Etapas',
              'body' => 'De por hacer a hecho.',
            ),
            1 => array(
              'icon' => 'arrows-pointing-out',
              'title' => 'Arrastra tarjetas',
              'body' => 'Según avanza.',
            ),
            2 => array(
              'icon' => 'user-plus',
              'title' => 'Asigna',
              'body' => 'Responsables por tarea.',
            ),
            3 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Fechas límite',
              'body' => 'En cada tarjeta.',
            ),
            4 => array(
              'icon' => 'eye',
              'title' => 'Claro',
              'body' => 'Campo y oficina.',
            ),
            5 => array(
              'icon' => 'bell-alert',
              'title' => 'Sin sorpresas',
              'body' => 'Nada se escapa.',
            ),
          ),
          'cta' => array(
            'heading' => 'Haz el trabajo evidente para todos.',
            'sub' => 'Un tablero que toda tu cuadrilla entiende de un vistazo.',
          ),
        ),
        'projects' => array(
          'icon' => 'folder',
          'title' => 'Proyectos',
          'body' => 'Cada trabajo mantiene juntos su alcance, documentos, costos e historial.',
          'hero' => 'Todo sobre un trabajo, en un solo lugar',
          'lead' => 'Cada proyecto mantiene juntos su alcance, cronograma, documentos, costos, fotos y conversaciones, para que toda la historia de un trabajo esté a un clic.',
          'rows' => array(
            0 => array(
              'heading' => 'Se acabó la información dispersa',
              'text' => 'Abre un proyecto y encuentra el presupuesto, el cronograma, los gastos, las fotos y los mensajes en un solo lugar. Nada vive en otra app o en un hilo de mensajes.',
              'points' => array(
                0 => 'Alcance, cronograma y documentos',
                1 => 'Costos y fotos juntos',
                2 => 'Conversaciones ligadas al trabajo',
                3 => 'El historial completo en un lugar',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Proyecto · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-text',
                    'label' => 'Alcance y presupuesto',
                    'sub' => '$48,000',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Cronograma',
                    'sub' => '62% completado',
                  ),
                  2 => array(
                    'icon' => 'photo',
                    'label' => 'Fotos',
                    'sub' => '24 archivadas',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'La única fuente de verdad',
              'text' => 'Como todo se conecta al proyecto, tu costeo, el portal del cliente y los informes se nutren del mismo lugar y se mantienen consistentes.',
              'points' => array(
                0 => 'Una fuente para cada detalle',
                1 => 'Impulsa el costeo y los informes',
                2 => 'Alimenta el portal del cliente',
                3 => 'Consistente en todas partes',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Cuando un trabajo vive en un solo lugar, dejas de perder tiempo buscando y empiezas a confiar en tus números.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'folder',
              'title' => 'Un solo lugar',
              'body' => 'Todo junto.',
            ),
            1 => array(
              'icon' => 'document-text',
              'title' => 'Documentos',
              'body' => 'Alcance y archivos.',
            ),
            2 => array(
              'icon' => 'calculator',
              'title' => 'Costos',
              'body' => 'Costeo en vivo.',
            ),
            3 => array(
              'icon' => 'photo',
              'title' => 'Fotos',
              'body' => 'Progreso archivado.',
            ),
            4 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'Mensajes',
              'body' => 'Ligados al trabajo.',
            ),
            5 => array(
              'icon' => 'clock',
              'title' => 'Historial',
              'body' => 'La historia completa.',
            ),
          ),
          'cta' => array(
            'heading' => 'Mantén cada trabajo en un solo lugar.',
            'sub' => 'Alcance, costos, fotos e historial, juntos.',
          ),
        ),
        'crew-scheduling' => array(
          'icon' => 'user-group',
          'title' => 'Programación de cuadrillas',
          'body' => 'Asigna personas a tareas y ve quién está disponible y cuándo.',
          'hero' => 'Pon a la persona correcta en el trabajo correcto',
          'lead' => 'Asigna cuadrilla a las tareas y ve quién está disponible y cuándo, para dejar de duplicar reservas y llevar un calendario más ajustado y rentable.',
          'rows' => array(
            0 => array(
              'heading' => 'Disponibilidad de un vistazo',
              'text' => 'Ve quién está libre, quién ocupado y quién sobrecargado antes de comprometer una fecha. Asigna a las personas correctas sin adivinar.',
              'points' => array(
                0 => 'Ve la disponibilidad entre trabajos',
                1 => 'Asigna personas a tareas',
                2 => 'Evita las reservas dobles',
                3 => 'Equilibra la carga de trabajo',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Equipo · martes',
                'rows' => array(
                  0 => array(
                    'icon' => 'user',
                    'label' => 'Greg M.',
                    'sub' => 'Maple St · obra en bruto',
                  ),
                  1 => array(
                    'icon' => 'user',
                    'label' => 'Tony R.',
                    'sub' => 'Oak Ave · estructura',
                  ),
                  2 => array(
                    'icon' => 'user',
                    'label' => 'Sam K.',
                    'sub' => 'Disponible',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Cada uno sabe dónde estar',
              'text' => 'Las asignaciones llegan al equipo, así se presentan en la obra correcta listos para trabajar. Sin llamadas matutinas para organizar el día.',
              'points' => array(
                0 => 'El equipo ve sus asignaciones',
                1 => 'Se presentan listos en la obra correcta',
                2 => 'Menos llamadas de última hora',
                3 => 'Un día más productivo',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un equipo que a las 7 AM sabe dónde estar factura más horas y gasta menos combustible.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'user-group',
              'title' => 'Asigna',
              'body' => 'Personas a tareas.',
            ),
            1 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Disponibilidad',
              'body' => 'Quién está libre.',
            ),
            2 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Sin duplicidades',
              'body' => 'Conflictos señalados.',
            ),
            3 => array(
              'icon' => 'scale',
              'title' => 'Equilibrado',
              'body' => 'Carga pareja.',
            ),
            4 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'El equipo lo ve',
              'body' => 'En su teléfono.',
            ),
            5 => array(
              'icon' => 'bolt',
              'title' => 'Productivo',
              'body' => 'Listos a las 7 AM.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja de duplicar a tu equipo.',
            'sub' => 'Consulta la disponibilidad y asigna a las personas correctas siempre.',
          ),
        ),
        'shared-schedules' => array(
          'icon' => 'paper-airplane',
          'title' => 'Horarios compartidos',
          'body' => 'Los enlaces de horario en vivo mantienen alineados a clientes y equipos automáticamente.',
          'hero' => 'Un horario que todos pueden ver',
          'lead' => 'Los enlaces de horario en vivo hacen que clientes y equipos vean el mismo plan actualizado, así los cambios llegan a todos al instante.',
          'rows' => array(
            0 => array(
              'heading' => 'Alineados sin trabajo extra',
              'text' => 'Comparte un enlace en vivo con clientes y equipo. Cuando una fecha cambia, su vista cambia también, sin mensajes grupales ni impresiones desfasadas.',
              'points' => array(
                0 => 'Enlaces en vivo para clientes y equipo',
                1 => 'Se actualiza al cambiar las fechas',
                2 => 'Sin mensajes masivos ni impresiones',
                3 => 'Todos en la misma página',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Compartido · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'paper-airplane',
                    'label' => 'Enlace de cliente',
                    'sub' => 'Qué sigue',
                  ),
                  1 => array(
                    'icon' => 'user-group',
                    'label' => 'Enlace de equipo',
                    'sub' => 'Horario completo',
                  ),
                  2 => array(
                    'icon' => 'bolt',
                    'label' => 'Auto-actualiza',
                    'sub' => 'Con cada cambio',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Menos malentendidos',
              'text' => 'Cuando todos ven el mismo plan, cesan las llamadas sobre fechas y el trabajo fluye. Los cambios se comunican por defecto.',
              'points' => array(
                0 => 'Se acaban las llamadas de fechas',
                1 => 'Cambios comunicados por defecto',
                2 => 'Clientes y equipo alineados',
                3 => 'Un trabajo más fluido',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un único horario compartido es la forma más barata de reducir el ida y vuelta diario sobre quién hace qué y cuándo.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'paper-airplane',
              'title' => 'Enlaces en vivo',
              'body' => 'Clientes y equipo.',
            ),
            1 => array(
              'icon' => 'bolt',
              'title' => 'Auto-actualiza',
              'body' => 'Con cada cambio.',
            ),
            2 => array(
              'icon' => 'users',
              'title' => 'Alineados',
              'body' => 'Un plan para todos.',
            ),
            3 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Cualquier dispositivo',
              'body' => 'Sin app.',
            ),
            4 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Al día',
              'body' => 'Nunca desfasado.',
            ),
            5 => array(
              'icon' => 'face-smile',
              'title' => 'Menos llamadas',
              'body' => 'Menos ida y vuelta.',
            ),
          ),
          'cta' => array(
            'heading' => 'Reúne a todos en un mismo horario.',
            'sub' => 'Enlaces en vivo que alinean a clientes y equipo.',
          ),
        ),
        'reminders' => array(
          'icon' => 'bell-alert',
          'title' => 'Recordatorios',
          'body' => 'Avisos automáticos antes del trabajo programado para que nada se pase.',
          'hero' => 'Avisos para que nada se escape',
          'lead' => 'Los recordatorios automáticos antes del trabajo, las inspecciones y los hitos te mantienen a ti y a tu equipo por delante de cada fecha: nada se escapa.',
          'rows' => array(
            0 => array(
              'heading' => 'Un aviso antes de que importe',
              'text' => 'Hive avisa a las personas correctas antes de una visita, una inspección o un plazo, así la preparación llega a tiempo y no se pierden fechas.',
              'points' => array(
                0 => 'Recordatorios antes del trabajo',
                1 => 'Aviso de inspecciones',
                2 => 'Alertas de hitos y plazos',
                3 => 'Enviados a las personas correctas',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Recordatorios',
                'rows' => array(
                  0 => array(
                    'icon' => 'bell-alert',
                    'label' => 'Inspección mañana',
                    'sub' => 'Maple St · 9 AM',
                  ),
                  1 => array(
                    'icon' => 'bell-alert',
                    'label' => 'Entrega de azulejos',
                    'sub' => 'Lun AM',
                  ),
                  2 => array(
                    'icon' => 'bell-alert',
                    'label' => 'El permiso vence',
                    'sub' => 'En 5 días',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Adelántate, no vayas detrás',
              'text' => 'En vez de reaccionar a fechas perdidas, te adelantas a ellas. Menos inspecciones fallidas, menos equipos parados, menos sorpresas costosas.',
              'points' => array(
                0 => 'Adelántate a cada fecha',
                1 => 'Menos inspecciones fallidas',
                2 => 'Menos mañanas de equipo parado',
                3 => 'Menos sorpresas costosas',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un recordatorio el día antes es mucho más barato que una inspección perdida o un equipo esperando sin hacer nada.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'bell-alert',
              'title' => 'Auto-avisos',
              'body' => 'Antes del trabajo.',
            ),
            1 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Inspecciones',
              'body' => 'Nunca perdidas.',
            ),
            2 => array(
              'icon' => 'flag',
              'title' => 'Hitos',
              'body' => 'Adelántate.',
            ),
            3 => array(
              'icon' => 'truck',
              'title' => 'Entregas',
              'body' => 'Prepárate.',
            ),
            4 => array(
              'icon' => 'users',
              'title' => 'Personas correctas',
              'body' => 'Alertas dirigidas.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Nada se escapa',
              'body' => 'Mantén el control.',
            ),
          ),
          'cta' => array(
            'heading' => 'No vuelvas a perder una fecha.',
            'sub' => 'Avisos automáticos antes de cada visita y plazo.',
          ),
        ),
      ),
    ),
    'team' => array(
      'label' => 'Equipo y Tiempo',
      'eyebrow' => 'Equipo y Tiempo',
      'grid_heading' => 'Tiempo y pago, en sincronía',
      'cards' => array(
        'time-tracking' => array(
          'icon' => 'clock',
          'title' => 'Registro de tiempo móvil',
          'body' => 'Los equipos registran horas por obra y tarea desde su teléfono.',
          'hero' => 'Horas registradas desde la obra',
          'lead' => 'Tu equipo registra el tiempo en la obra y tarea correctas desde su teléfono, así el costo de mano de obra es exacto, capturado en vivo y nunca reconstruido un viernes.',
          'rows' => array(
            0 => array(
              'heading' => 'Ficha desde el terreno',
              'text' => 'Sin tarjetas de tiempo en papel ni adivinanzas a fin de semana. El equipo toca para iniciar y detener el tiempo en la obra y tarea que trabaja.',
              'points' => array(
                0 => 'Registra tiempo por obra y tarea',
                1 => 'Inicia y detén desde cualquier teléfono',
                2 => 'Funciona en obra, sin oficina',
                3 => 'Precisión al minuto',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Esta semana · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'clock',
                    'label' => 'Greg M. · Plomería',
                    'sub' => '32.5 h',
                  ),
                  1 => array(
                    'icon' => 'clock',
                    'label' => 'Tony R. · Estructura',
                    'sub' => '28.0 h',
                  ),
                  2 => array(
                    'icon' => 'clock',
                    'label' => 'Sam K. · Azulejos',
                    'sub' => '18.0 h',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'La mano de obra aterriza en el trabajo',
              'text' => 'Cada hora fluye directo al costeo de trabajos, así el costo de mano de obra aparece en el proyecto correcto a medida que avanza el trabajo.',
              'points' => array(
                0 => 'Las horas alimentan el costeo de trabajos',
                1 => 'Costo de mano de obra en el trabajo correcto',
                2 => 'Míralo en vivo, no después',
                3 => 'Sin volver a capturar en hojas de cálculo',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'La mano de obra es tu mayor costo. Rastrearla en vivo por trabajo es cómo descubres qué trabajo de verdad genera dinero.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clock',
              'title' => 'Por trabajo y tarea',
              'body' => 'Tiempo en el trabajo correcto.',
            ),
            1 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Desde el teléfono',
              'body' => 'Ficha desde cualquier lugar.',
            ),
            2 => array(
              'icon' => 'bolt',
              'title' => 'En vivo',
              'body' => 'Capturado al instante.',
            ),
            3 => array(
              'icon' => 'calculator',
              'title' => 'Costeado por trabajo',
              'body' => 'Alimenta el costeo.',
            ),
            4 => array(
              'icon' => 'document-text',
              'title' => 'Sin papel',
              'body' => 'Adiós a las tarjetas.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Preciso',
              'body' => 'Al minuto.',
            ),
          ),
          'cta' => array(
            'heading' => 'Obtén horas precisas desde el campo.',
            'sub' => 'Las cuadrillas fichan por trabajo y la mano de obra aterriza donde corresponde.',
          ),
        ),
        'timesheet-approval' => array(
          'icon' => 'check-circle',
          'title' => 'Aprobación de horas',
          'body' => 'Revisa y aprueba las horas antes de que lleguen a la nómina.',
          'hero' => 'Aprueba la semana en unos toques',
          'lead' => 'Revisa las horas de tu cuadrilla, corrige lo que falle y aprueba antes de que salga un solo dólar de nómina — así pagas por el tiempo realmente trabajado.',
          'rows' => array(
            0 => array(
              'heading' => 'Revisa antes de pagar',
              'text' => 'Ve toda la semana por persona y trabajo, detecta cualquier cosa rara y aprueba con confianza. Sin sorpresas el día de pago.',
              'points' => array(
                0 => 'Horas por persona y trabajo',
                1 => 'Detecta errores antes de la nómina',
                2 => 'Edita y aprueba en un toque',
                3 => 'Un rastro claro de aprobaciones',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Pendiente de aprobación',
                'rows' => array(
                  0 => array(
                    'icon' => 'clock',
                    'label' => 'Greg M.',
                    'sub' => '40.0 h · revisar',
                  ),
                  1 => array(
                    'icon' => 'clock',
                    'label' => 'Tony R.',
                    'sub' => '38.5 h · revisar',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Sam K.',
                    'sub' => '36.0 h · aprobado',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Directo a la nómina',
              'text' => 'Las horas aprobadas fluyen a los pagos y al costeo de trabajos a la vez, así lo que apruebas es exactamente lo que pagas y lo que se carga al trabajo.',
              'points' => array(
                0 => 'Las horas aprobadas alimentan la nómina',
                1 => 'Y alimentan el costeo de trabajos',
                2 => 'El pago coincide con lo trabajado',
                3 => 'Un registro consistente',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Un rápido paso de aprobación detecta los errores que en silencio te cuestan dinero semana tras semana.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'check-circle',
              'title' => 'Aprueba',
              'body' => 'Antes de la nómina.',
            ),
            1 => array(
              'icon' => 'eye',
              'title' => 'Revisa',
              'body' => 'Por persona y trabajo.',
            ),
            2 => array(
              'icon' => 'pencil',
              'title' => 'Edita',
              'body' => 'Corrige lo que falle.',
            ),
            3 => array(
              'icon' => 'banknotes',
              'title' => 'A la nómina',
              'body' => 'Fluye directo.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Al costeo',
              'body' => 'En el trabajo correcto.',
            ),
            5 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Rastro',
              'body' => 'Aprobaciones claras.',
            ),
          ),
          'cta' => array(
            'heading' => 'Paga por las horas que se trabajaron.',
            'sub' => 'Revisa y aprueba antes de correr la nómina.',
          ),
        ),
        'payroll-payments' => array(
          'icon' => 'banknotes',
          'title' => 'Pagos de nómina',
          'body' => 'Paga a tu equipo desde horas aprobadas en un solo flujo.',
          'hero' => 'Paga a tu cuadrilla desde la misma pantalla',
          'lead' => 'Las horas aprobadas pasan directo a los pagos, así pagar a tu equipo es un flujo limpio — registrado en el trabajo y conciliado con tus libros.',
          'rows' => array(
            0 => array(
              'heading' => 'De aprobado a pagado',
              'text' => 'Sin volver a teclear horas en otro sistema. El tiempo aprobado se convierte en un pago que puedes revisar y enviar, con las cuentas ya hechas.',
              'points' => array(
                0 => 'Nómina desde horas aprobadas',
                1 => 'Tarifa y totales calculados',
                2 => 'Revisa y envía',
                3 => 'Registrado en el trabajo',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Pago · Greg M.',
                'rows' => array(
                  0 => array(
                    'label' => '32.5 h × $42',
                    'value' => '$1,365.00',
                  ),
                  1 => array(
                    'label' => 'Saldo anterior',
                    'value' => '$0.00',
                  ),
                  2 => array(
                    'label' => 'Pago esta semana',
                    'value' => '$1,365.00',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Libros limpios, siempre',
              'text' => 'Cada pago se registra y sincroniza con tus libros y el costeo de trabajos, así la nómina nunca desequilibra tus números.',
              'points' => array(
                0 => 'Sincronizado con tus libros',
                1 => 'Aterriza en el costeo de trabajos',
                2 => 'Costo preciso por trabajo',
                3 => 'Sin un silo de nómina aparte',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una nómina que fluye de horas aprobadas a tus libros significa que tu costo de mano de obra siempre está correcto — automáticamente.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'banknotes',
              'title' => 'Desde horas',
              'body' => 'De aprobado a pagado.',
            ),
            1 => array(
              'icon' => 'calculator',
              'title' => 'Calculado',
              'body' => 'Las cuentas listas.',
            ),
            2 => array(
              'icon' => 'eye',
              'title' => 'Revisa',
              'body' => 'Antes de enviar.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'En el trabajo',
              'body' => 'Costo registrado.',
            ),
            4 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Sincronizado',
              'body' => 'Cuadra con los libros.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'A tiempo',
              'body' => 'Cuadrilla bien pagada.',
            ),
          ),
          'cta' => array(
            'heading' => 'Corre la nómina sin la hoja de cálculo.',
            'sub' => 'Las horas aprobadas se vuelven pagos en un solo flujo.',
          ),
        ),
        'running-balances' => array(
          'icon' => 'scale',
          'title' => 'Saldos actuales',
          'body' => 'Siempre sabe cuánto le debes a cada trabajador a la fecha.',
          'hero' => 'Siempre sabe cuánto debes',
          'lead' => 'Rastrea un saldo actual por cada trabajador y subcontratista, así siempre sabes exactamente cuánto debes y nunca pierdes de vista un adelanto o un pago parcial.',
          'rows' => array(
            0 => array(
              'heading' => 'Un saldo en vivo por persona',
              'text' => 'Cada hora, pago y adelanto ajusta el saldo, así que el número que ves es siempre lo que realmente debes — hasta el último dólar.',
              'points' => array(
                0 => 'Saldo en vivo por trabajador',
                1 => 'Considera los adelantos',
                2 => 'Maneja pagos parciales',
                3 => 'Siempre preciso',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Saldos',
                'rows' => array(
                  0 => array(
                    'label' => 'Greg M.',
                    'value' => '$0.00',
                  ),
                  1 => array(
                    'label' => 'Tony R.',
                    'value' => '$420.00',
                  ),
                  2 => array(
                    'label' => 'Sam K.',
                    'value' => '$1,365.00',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Sin conversaciones incómodas',
              'text' => 'Cuando un trabajador pregunta cuánto se le debe, tienes la respuesta al instante: sin buscar, sin disputas, sin erosionar la confianza.',
              'points' => array(
                0 => 'Responde "¿cuánto se me debe?" al instante',
                1 => 'Evita disputas de pago',
                2 => 'Mantén alta la confianza de la cuadrilla',
                3 => 'Historial claro para ambas partes',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una cuadrilla que confía en que le pagarán bien —y puede verlo— es una cuadrilla que se queda.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'scale',
              'title' => 'Por persona',
              'body' => 'Saldo en vivo por cada uno.',
            ),
            1 => array(
              'icon' => 'arrow-trending-up',
              'title' => 'Anticipos',
              'body' => 'Registrados con claridad.',
            ),
            2 => array(
              'icon' => 'banknotes',
              'title' => 'Pago parcial',
              'body' => 'Gestionado bien.',
            ),
            3 => array(
              'icon' => 'bolt',
              'title' => 'Siempre en vivo',
              'body' => 'Al minuto.',
            ),
            4 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'Sin disputas',
              'body' => 'Respuestas claras.',
            ),
            5 => array(
              'icon' => 'clock',
              'title' => 'Historial',
              'body' => 'Para ambas partes.',
            ),
          ),
          'cta' => array(
            'heading' => 'Sabe cuánto se le debe a cada trabajador.',
            'sub' => 'Un saldo en vivo para toda tu cuadrilla.',
          ),
        ),
        'roles-permissions' => array(
          'icon' => 'lock-closed',
          'title' => 'Roles y permisos',
          'body' => 'Controla quién puede ver finanzas, clientes y ajustes.',
          'hero' => 'Da a cada persona exactamente el acceso que necesita',
          'lead' => 'Los roles y permisos dejan que tu equipo haga su trabajo sin ver tus finanzas, tu lista de clientes ni tus ajustes, para que delegues sin preocuparte.',
          'rows' => array(
            0 => array(
              'heading' => 'El acceso justo para cada rol',
              'text' => 'Un capataz ve horarios y tiempo; la oficina ve clientes y facturas; solo tú ves el panorama financiero completo. Configúralo una vez y relájate.',
              'points' => array(
                0 => 'Controla el acceso por rol',
                1 => 'Oculta las finanzas del campo',
                2 => 'Limita quién edita los ajustes',
                3 => 'Delega con confianza',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Roles',
                'rows' => array(
                  0 => array(
                    'icon' => 'user',
                    'label' => 'Capataz',
                    'sub' => 'Horario y tiempo',
                  ),
                  1 => array(
                    'icon' => 'user',
                    'label' => 'Oficina',
                    'sub' => 'Clientes y facturas',
                  ),
                  2 => array(
                    'icon' => 'lock-closed',
                    'label' => 'Propietario',
                    'sub' => 'Acceso total',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Haz crecer al equipo con seguridad',
              'text' => 'A medida que sumas gente, entregas solo el acceso que necesitan. Tus cifras sensibles siguen privadas aunque más manos usen el sistema.',
              'points' => array(
                0 => 'Incorpora gente nueva con seguridad',
                1 => 'Mantén privados los datos sensibles',
                2 => 'Reduce errores costosos',
                3 => 'Escala sin perder el control',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Solo creces tan rápido como puedes delegar. Los roles te dejan ceder el trabajo sin ceder tus libros.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'lock-closed',
              'title' => 'Por rol',
              'body' => 'Acceso a medida.',
            ),
            1 => array(
              'icon' => 'eye-slash',
              'title' => 'Oculta finanzas',
              'body' => 'Del campo.',
            ),
            2 => array(
              'icon' => 'cog-6-tooth',
              'title' => 'Bloqueo de ajustes',
              'body' => 'Limita ediciones.',
            ),
            3 => array(
              'icon' => 'user-plus',
              'title' => 'Incorpora',
              'body' => 'Suma gente seguro.',
            ),
            4 => array(
              'icon' => 'shield-check',
              'title' => 'Privado',
              'body' => 'Datos sensibles a salvo.',
            ),
            5 => array(
              'icon' => 'arrow-trending-up',
              'title' => 'Escala',
              'body' => 'Crece con control.',
            ),
          ),
          'cta' => array(
            'heading' => 'Delega sin ceder los libros.',
            'sub' => 'Da a cada persona exactamente el acceso que necesita.',
          ),
        ),
        'job-costing' => array(
          'icon' => 'calculator',
          'title' => 'Costeo de obra',
          'body' => 'El costo de mano de obra cae en el proyecto correcto automáticamente.',
          'hero' => 'Costo de mano de obra en la obra correcta, automáticamente',
          'lead' => 'Cada hora aprobada cae en el proyecto donde se trabajó, así la mano de obra —tu mayor costo— aparece en el costeo sin que nadie sume tarjetas de tiempo.',
          'rows' => array(
            0 => array(
              'heading' => 'Mano de obra que se costea sola',
              'text' => 'Como las cuadrillas registran el tiempo por obra, sus horas y pago fluyen al costo de cada proyecto automáticamente. Sin planillas de asignación, sin estimaciones.',
              'points' => array(
                0 => 'Las horas van a la obra correcta',
                1 => 'Las tarifas se suman al costo',
                2 => 'Sin asignación manual',
                3 => 'Siempre al día',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Maple St · Mano de obra',
                'rows' => array(
                  0 => array(
                    'label' => 'Horas a la fecha',
                    'value' => '186',
                  ),
                  1 => array(
                    'label' => 'Costo de mano de obra',
                    'value' => '$7,940',
                  ),
                  2 => array(
                    'label' => '% del presupuesto',
                    'value' => '71%',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Ve qué trabajo rinde',
              'text' => 'Con la mano de obra costeada con precisión, por fin ves qué obras y tareas de verdad ganan dinero, y presupuestas las siguientes con más criterio.',
              'points' => array(
                0 => 'Costo real de mano de obra por obra',
                1 => 'Detecta el trabajo rentable',
                2 => 'Capta los sobrecostos a tiempo',
                3 => 'Presupuesta mejor la próxima obra',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'La mayoría de los contratistas subvalora la mano de obra porque nunca la registra por obra. Hive te muestra la cifra real.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'calculator',
              'title' => 'Auto-costeada',
              'body' => 'Mano de obra en la obra.',
            ),
            1 => array(
              'icon' => 'clock',
              'title' => 'Desde las horas',
              'body' => 'Registradas por obra.',
            ),
            2 => array(
              'icon' => 'bolt',
              'title' => 'En vivo',
              'body' => 'Siempre al día.',
            ),
            3 => array(
              'icon' => 'chart-bar',
              'title' => 'Vista de ganancia',
              'body' => 'Ve qué rinde.',
            ),
            4 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Sobrecostos',
              'body' => 'Captados a tiempo.',
            ),
            5 => array(
              'icon' => 'light-bulb',
              'title' => 'Mejores presupuestos',
              'body' => 'Ponle el precio justo.',
            ),
          ),
          'cta' => array(
            'heading' => 'Costea tu mano de obra sin cuentas.',
            'sub' => 'Las horas aprobadas caen en la obra correcta automáticamente.',
          ),
        ),
      ),
    ),
    'communication' => array(
      'label' => 'Comunicación',
      'eyebrow' => 'Comunicación',
      'grid_heading' => 'Cada conversación, capturada',
      'cards' => array(
        'shared-inbox' => array(
          'icon' => 'chat-bubble-left-right',
          'title' => 'Bandeja compartida',
          'body' => 'Todo tu equipo trabaja un mismo conjunto de conversaciones, sin números personales.',
          'hero' => 'Una bandeja para todo tu equipo',
          'lead' => 'Las llamadas y textos pasan por una línea de negocio compartida, así tu equipo trabaja las mismas conversaciones y ningún hilo con clientes vive en un teléfono personal.',
          'rows' => array(
            0 => array(
              'heading' => 'Se acabaron los números personales',
              'text' => 'Clientes y subcontratistas escriben a un número de negocio. Cualquiera de tu equipo puede retomar el hilo, y la conversación se queda en la empresa, no en el teléfono de un empleado.',
              'points' => array(
                0 => 'Una línea de negocio compartida',
                1 => 'El equipo trabaja los mismos hilos',
                2 => 'Ningún cliente en un teléfono personal',
                3 => 'Continuidad al cambiar el personal',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Bandeja compartida',
                'rows' => array(
                  0 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Los Henderson',
                    'sub' => 'Pregunta sobre azulejos',
                  ),
                  1 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Programar',
                  ),
                  2 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Inspector municipal',
                    'sub' => 'Confirmado',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Vinculado al trabajo',
              'text' => 'Cada conversación se conecta con el cliente y el proyecto correctos, así el contexto nunca se pierde y quien intervenga está al día al instante.',
              'points' => array(
                0 => 'Hilos vinculados a los trabajos',
                1 => 'Contexto completo para quien responda',
                2 => 'Nada se pierde en un mensaje privado',
                3 => 'Historial con búsqueda',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Cuando las conversaciones con clientes viven en la empresa, nunca pierdes una relación porque un empleado se vaya.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'Línea compartida',
              'body' => 'Un número de empresa.',
            ),
            1 => array(
              'icon' => 'users',
              'title' => 'Acceso del equipo',
              'body' => 'Cualquiera puede responder.',
            ),
            2 => array(
              'icon' => 'folder',
              'title' => 'Vinculado al trabajo',
              'body' => 'Vinculado a los proyectos.',
            ),
            3 => array(
              'icon' => 'eye-slash',
              'title' => 'Sin número personal',
              'body' => 'Privacidad protegida.',
            ),
            4 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Con búsqueda',
              'body' => 'Encuentra cualquier hilo.',
            ),
            5 => array(
              'icon' => 'shield-check',
              'title' => 'Continuidad',
              'body' => 'Se queda contigo.',
            ),
          ),
          'cta' => array(
            'heading' => 'Conserva los hilos con clientes en la empresa.',
            'sub' => 'Una bandeja compartida, sin números personales.',
          ),
        ),
        'translations' => array(
          'icon' => 'language',
          'title' => 'Traducciones',
          'body' => 'Escribe a las cuadrillas en su idioma y lee sus respuestas en el tuyo.',
          'hero' => 'Habla con cada cuadrilla en su idioma',
          'lead' => 'Escribe a un subcontratista o miembro de la cuadrilla en su idioma y lee sus respuestas en el tuyo, automáticamente, para que el idioma nunca frene un trabajo.',
          'rows' => array(
            0 => array(
              'heading' => 'Dos idiomas, un solo hilo',
              'text' => 'Tú escribes en inglés y ellos lo leen en español; responden en español y tú lo lees en inglés. La traducción ocurre en el mensaje, en ambos sentidos.',
              'points' => array(
                0 => 'Envía y recibe traducido',
                1 => 'Funciona en ambos sentidos',
                2 => 'En el mismo hilo',
                3 => 'Sin app aparte',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Hilo · Tony R.',
                'rows' => array(
                  0 => array(
                    'icon' => 'language',
                    'label' => 'Tú (EN)',
                    'sub' => 'Empieza el azulejo el lunes',
                  ),
                  1 => array(
                    'icon' => 'language',
                    'label' => 'Tony (ES)',
                    'sub' => 'Entendido, lunes',
                  ),
                  2 => array(
                    'icon' => 'check-badge',
                    'label' => 'Tú lees',
                    'sub' => 'Entendido, lunes',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Menos errores en la obra',
              'text' => 'Instrucciones claras en el idioma que alguien realmente lee significan menos retrabajo, menos confusiones y una obra más segura.',
              'points' => array(
                0 => 'Instrucciones más claras',
                1 => 'Menos retrabajo y confusiones',
                2 => 'Una obra más segura',
                3 => 'Relaciones más sólidas con la cuadrilla',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una instrucción mal entendida puede costar un día. Traducir en el hilo mantiene a todos, literalmente, en la misma página.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'language',
              'title' => 'En ambos sentidos',
              'body' => 'Envía y lee.',
            ),
            1 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'En el hilo',
              'body' => 'La misma conversación.',
            ),
            2 => array(
              'icon' => 'bolt',
              'title' => 'Automático',
              'body' => 'Sin pasos extra.',
            ),
            3 => array(
              'icon' => 'shield-check',
              'title' => 'Menos errores',
              'body' => 'Instrucciones claras.',
            ),
            4 => array(
              'icon' => 'users',
              'title' => 'Cualquier cuadrilla',
              'body' => 'Llega a todos.',
            ),
            5 => array(
              'icon' => 'face-smile',
              'title' => 'Mejores vínculos',
              'body' => 'Equipos más sólidos.',
            ),
          ),
          'cta' => array(
            'heading' => 'Nunca dejes que el idioma frene un trabajo.',
            'sub' => 'Escribe a las cuadrillas en su idioma y lee las respuestas en el tuyo.',
          ),
        ),
        'text-to-task' => array(
          'icon' => 'calendar-date-range',
          'title' => 'De mensaje a tarea',
          'body' => 'Convierte un mensaje entrante en una tarea programada con IA, revisada antes de guardar.',
          'hero' => 'Convierte un mensaje en tarea, al instante',
          'lead' => 'Cuando un cliente o subcontratista escribe algo por hacer, la IA redacta una tarea programada a partir de ello, lista para que la revises y guardes con un toque.',
          'rows' => array(
            0 => array(
              'heading' => 'Capta la petición, crea la tarea',
              'text' => 'Un mensaje como «¿puedes arreglar también la puerta trasera el jueves?» se convierte en un borrador de tarea con el trabajo y la fecha correctos, y así nunca se pierde en el hilo.',
              'points' => array(
                0 => 'La IA lee el mensaje',
                1 => 'Redacta una tarea con trabajo y fecha',
                2 => 'Revisas antes de guardar',
                3 => 'Nada se escapa',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Tarea sugerida',
                'rows' => array(
                  0 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'De: Henderson',
                    'sub' => 'Arreglar puerta trasera jue.',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Tarea redactada',
                    'sub' => 'Maple St · jue.',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Revisar y guardar',
                    'sub' => 'Un toque',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Revisado, nunca a ciegas',
              'text' => 'La IA sugiere; tú decides. Cada tarea se muestra para tu aprobación antes de entrar en el calendario, así mantienes el control.',
              'points' => array(
                0 => 'Apruebas cada tarea',
                1 => 'Edita antes de guardar',
                2 => 'Mantén el control total',
                3 => 'Sin sorpresas en el calendario',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Las pequeñas peticiones enterradas en los mensajes son las que se olvidan. De mensaje a tarea asegura que se programen.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'sparkles',
              'title' => 'La IA lee',
              'body' => 'Detecta la petición.',
            ),
            1 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Redacta la tarea',
              'body' => 'Trabajo y fecha.',
            ),
            2 => array(
              'icon' => 'check-circle',
              'title' => 'Revisado',
              'body' => 'Tú apruebas.',
            ),
            3 => array(
              'icon' => 'pencil',
              'title' => 'Editable',
              'body' => 'Ajusta primero.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'En el trabajo',
              'body' => 'Proyecto correcto.',
            ),
            5 => array(
              'icon' => 'bell-alert',
              'title' => 'Nada se pierde',
              'body' => 'Siempre registrado.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja de perder peticiones en el hilo.',
            'sub' => 'Convierte un mensaje en una tarea programada con un toque.',
          ),
        ),
        'recorded-calls' => array(
          'icon' => 'microphone',
          'title' => 'Llamadas grabadas',
          'body' => 'Cada llamada capturada, transcrita y resumida automáticamente.',
          'hero' => 'Cada llamada capturada y resumida',
          'lead' => 'Las llamadas se graban, transcriben y resumen con puntos de acción, para que nunca más pierdas lo que se prometió por teléfono.',
          'rows' => array(
            0 => array(
              'heading' => 'Nunca dependas de la memoria',
              'text' => 'Cada llamada se graba y transcribe, luego se resume en los puntos clave y las acciones, adjunta al cliente y al trabajo correctos.',
              'points' => array(
                0 => 'Llamadas grabadas y transcritas',
                1 => 'Resumidas con puntos de acción',
                2 => 'Adjuntas al cliente y al trabajo',
                3 => 'Con búsqueda para después',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Llamada · Henderson',
                'rows' => array(
                  0 => array(
                    'icon' => 'microphone',
                    'label' => 'Grabada',
                    'sub' => '6:42',
                  ),
                  1 => array(
                    'icon' => 'document-text',
                    'label' => 'Transcripción',
                    'sub' => 'Lista',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Tarea pendiente',
                    'sub' => 'Enviar presupuesto de azulejos',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Resuelve disputas de "tú dijiste"',
              'text' => 'Cuando surge una duda sobre lo acordado por teléfono, tienes la grabación y el resumen: se acabó el "dijo, dijo".',
              'points' => array(
                0 => 'Prueba de lo que se dijo',
                1 => 'Acaba con disputas por teléfono',
                2 => 'Responsabiliza a los subcontratistas',
                3 => 'Protege tu negocio',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Las promesas hechas por teléfono son las que más se olvidan. Grabarlas protege a todos.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'microphone',
              'title' => 'Grabadas',
              'body' => 'Cada llamada.',
            ),
            1 => array(
              'icon' => 'document-text',
              'title' => 'Transcritas',
              'body' => 'Texto completo.',
            ),
            2 => array(
              'icon' => 'sparkles',
              'title' => 'Resumidas',
              'body' => 'Puntos clave.',
            ),
            3 => array(
              'icon' => 'check-circle',
              'title' => 'Tareas',
              'body' => 'Extraídas.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Adjuntas',
              'body' => 'Al proyecto.',
            ),
            5 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Buscables',
              'body' => 'Encuéntralas luego.',
            ),
          ),
          'cta' => array(
            'heading' => 'No pierdas nunca una promesa telefónica.',
            'sub' => 'Llamadas grabadas, transcritas y resumidas por ti.',
          ),
        ),
        'email-tracking' => array(
          'icon' => 'envelope',
          'title' => 'Seguimiento de correos',
          'body' => 'Sabe cuándo se abren los correos importantes y guarda el registro en el proyecto.',
          'hero' => 'Sabe cuándo llegan tus correos',
          'lead' => 'Ve cuándo se abren los correos importantes y guarda cada mensaje en el proyecto, para saber si el cliente realmente vio ese presupuesto.',
          'rows' => array(
            0 => array(
              'heading' => 'Se acabaron las dudas',
              'text' => 'Envía un presupuesto o una actualización y ve cuándo se abre. Sabes si insistir o darles espacio, en vez de adivinar.',
              'points' => array(
                0 => 'Ve cuándo se abren los correos',
                1 => 'Sabe si dar seguimiento',
                2 => 'Elige el momento del contacto',
                3 => 'Deja de adivinar',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Enviado · Presupuesto',
                'rows' => array(
                  0 => array(
                    'icon' => 'envelope',
                    'label' => 'Entregado',
                    'sub' => 'Lun 9:10',
                  ),
                  1 => array(
                    'icon' => 'eye',
                    'label' => 'Abierto',
                    'sub' => 'Lun 9:14',
                  ),
                  2 => array(
                    'icon' => 'eye',
                    'label' => 'Abierto de nuevo',
                    'sub' => 'Mar 7:02',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'En registro, en el proyecto',
              'text' => 'Los correos importantes se guardan con el cliente y el proyecto, así el historial vive donde está el trabajo, no perdido en una bandeja personal.',
              'points' => array(
                0 => 'Correos guardados en el proyecto',
                1 => 'Un historial claro',
                2 => 'Fuera de bandejas personales',
                3 => 'Fáciles de consultar',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Saber que un cliente abrió tu presupuesto tres veces te dice exactamente cuándo llamar.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'eye',
              'title' => 'Rastreo de aperturas',
              'body' => 'Ve cuándo se lee.',
            ),
            1 => array(
              'icon' => 'clock',
              'title' => 'Momento justo',
              'body' => 'Contacta a tiempo.',
            ),
            2 => array(
              'icon' => 'folder',
              'title' => 'En el proyecto',
              'body' => 'Queda en registro.',
            ),
            3 => array(
              'icon' => 'document-text',
              'title' => 'Historial',
              'body' => 'Historia clara.',
            ),
            4 => array(
              'icon' => 'envelope',
              'title' => 'Entrega',
              'body' => 'Envío confirmado.',
            ),
            5 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Referencia',
              'body' => 'Encuéntralo rápido.',
            ),
          ),
          'cta' => array(
            'heading' => 'Sabe si de verdad lo vieron.',
            'sub' => 'Rastreo de aperturas y registros que viven en el proyecto.',
          ),
        ),
        'client-updates' => array(
          'icon' => 'users',
          'title' => 'Novedades al cliente',
          'body' => 'Envía a los propietarios actualizaciones de agenda y estado sin esfuerzo extra.',
          'hero' => 'Mantén al cliente al día, sin esfuerzo',
          'lead' => 'Envía a los propietarios actualizaciones de agenda y estado de forma automática, para que estén informados y tranquilos mientras te concentras en la obra.',
          'rows' => array(
            0 => array(
              'heading' => 'Novedades que se envían solas',
              'text' => 'A medida que la obra avanza y cambian las fechas, el cliente recibe la novedad por el portal y las notificaciones, sin que escribas un mensaje aparte.',
              'points' => array(
                0 => 'Novedades de estado y agenda',
                1 => 'Enviadas por el portal',
                2 => 'Sin mensajes extra que escribir',
                3 => 'Clientes siempre tranquilos',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Enviado al cliente',
                'rows' => array(
                  0 => array(
                    'icon' => 'eye',
                    'label' => 'Novedad de estado',
                    'sub' => 'Eléctrica iniciada',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Agenda',
                    'sub' => 'Azulejo lun 6/7',
                  ),
                  2 => array(
                    'icon' => 'photo',
                    'label' => 'Fotos nuevas',
                    'sub' => '4 añadidas',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Clientes felices, menos llamadas',
              'text' => 'Un cliente informado llama menos y confía más. Un goteo constante de novedades te hace ver organizado y al mando de cada obra.',
              'points' => array(
                0 => 'Menos llamadas de "¿alguna novedad?"',
                1 => 'Más confianza del cliente',
                2 => 'Luce organizado y profesional',
                3 => 'Mejores reseñas y referencias',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Las novedades proactivas son el marketing más barato que tienes: convierten clientes en referencias.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'bolt',
              'title' => 'Automático',
              'body' => 'Se envía solo.',
            ),
            1 => array(
              'icon' => 'eye',
              'title' => 'Estado',
              'body' => 'Avance compartido.',
            ),
            2 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Agenda',
              'body' => 'Qué sigue.',
            ),
            3 => array(
              'icon' => 'photo',
              'title' => 'Fotos',
              'body' => 'Fotos del avance.',
            ),
            4 => array(
              'icon' => 'face-smile',
              'title' => 'Menos llamadas',
              'body' => 'Clientes tranquilos.',
            ),
            5 => array(
              'icon' => 'star',
              'title' => 'Referencias',
              'body' => 'Mejores reseñas.',
            ),
          ),
          'cta' => array(
            'heading' => 'Mantén al cliente al día en piloto automático.',
            'sub' => 'Novedades de estado y agenda que se envían solas.',
          ),
        ),
      ),
    ),
    'automation' => array(
      'label' => 'Automatización e IA',
      'eyebrow' => 'Automatización e IA',
      'grid_heading' => 'Deja que el trabajo tedioso se haga solo',
      'cards' => array(
        'receipt-ai' => array(
          'icon' => 'document-magnifying-glass',
          'title' => 'IA de recibos',
          'body' => 'Lee proveedores, totales y partidas de cualquier recibo.',
          'hero' => 'Recibos que se leen solos',
          'lead' => 'Fotografía o reenvía un recibo y la IA extrae el proveedor, el total, la fecha y cada partida: tus libros se llenan sin una sola tecla.',
          'rows' => array(
            0 => array(
              'heading' => 'De la foto al asiento',
              'text' => 'Ya sea un recibo de papel arrugado o un PDF por correo, la IA lo lee y crea un gasto limpio con partidas, listo para asignar a una obra.',
              'points' => array(
                0 => 'Lee proveedor, total y fecha',
                1 => 'Captura cada partida',
                2 => 'Funciona con fotos y PDF',
                3 => 'Crea un gasto limpio',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Recibo · Menards',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-magnifying-glass',
                    'label' => 'Proveedor',
                    'sub' => 'Menards',
                  ),
                  1 => array(
                    'icon' => 'banknotes',
                    'label' => 'Total',
                    'sub' => '$312.84',
                  ),
                  2 => array(
                    'icon' => 'list-bullet',
                    'label' => 'Partidas',
                    'sub' => '11 registradas',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Horas recuperadas cada semana',
              'text' => 'Se acabó teclear recibos en una hoja de cálculo por la noche. La captura de datos ocurre en cuanto llega el recibo, con el detalle de cada partida intacto.',
              'points' => array(
                0 => 'Sin captura manual de datos',
                1 => 'Detalle por partida conservado',
                2 => 'Horas ahorradas cada semana',
                3 => 'Libros siempre al día',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'El montón de recibos es donde muere la contabilidad. Leerlos automáticamente es como te mantienes al día.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-magnifying-glass',
              'title' => 'Lo lee',
              'body' => 'Proveedor y total.',
            ),
            1 => array(
              'icon' => 'list-bullet',
              'title' => 'Partidas',
              'body' => 'Cada partida.',
            ),
            2 => array(
              'icon' => 'camera',
              'title' => 'Cualquier formato',
              'body' => 'Foto o PDF.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'A un trabajo',
              'body' => 'Asigna rápido.',
            ),
            4 => array(
              'icon' => 'bolt',
              'title' => 'Al instante',
              'body' => 'Sin teclear.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Preciso',
              'body' => 'Gasto limpio.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja de teclear recibos.',
            'sub' => 'La IA lee el proveedor, el total y cada partida por ti.',
          ),
        ),
        'vendor-matching' => array(
          'icon' => 'arrows-right-left',
          'title' => 'Emparejamiento de proveedores',
          'body' => 'Las transacciones se emparejan solas con el proveedor y el trabajo correctos.',
          'hero' => 'Transacciones que se ordenan solas',
          'lead' => 'Las transacciones de banco y tarjeta se emparejan solas con el proveedor y el trabajo correctos, así que conciliar deja de ser una tarea de fin de semana.',
          'rows' => array(
            0 => array(
              'heading' => 'El emparejamiento está hecho por ti',
              'text' => 'La IA reconoce proveedores en descripciones bancarias confusas y vincula cada transacción con el proveedor y el trabajo correctos, aprendiendo tus patrones sobre la marcha.',
              'points' => array(
                0 => 'Reconoce descripciones confusas',
                1 => 'Vincula al proveedor y al trabajo',
                2 => 'Aprende tus patrones',
                3 => 'Menos cada semana',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Emparejado automáticamente',
                'rows' => array(
                  0 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'SQ *RIVERA PLB',
                    'sub' => 'Rivera Plumbing',
                  ),
                  1 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'MENARDS #214',
                    'sub' => 'Menards · Maple St',
                  ),
                  2 => array(
                    'icon' => 'check-badge',
                    'label' => 'Confianza',
                    'sub' => 'Alta',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Libros que se concilian rápido',
              'text' => 'Con las transacciones pre-emparejadas, solo confirmas en vez de categorizar. La conciliación pasa de horas a minutos.',
              'points' => array(
                0 => 'Confirma en vez de categorizar',
                1 => 'Las horas se vuelven minutos',
                2 => 'Menos costes mal categorizados',
                3 => 'El coste por trabajo se mantiene preciso',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Emparejar transacciones a mano es la parte más lenta de los libros. Automatizarlo son horas recuperadas cada mes.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Auto-emparejado',
              'body' => 'Proveedor y trabajo.',
            ),
            1 => array(
              'icon' => 'sparkles',
              'title' => 'Aprende',
              'body' => 'Tus patrones.',
            ),
            2 => array(
              'icon' => 'check-badge',
              'title' => 'Con confianza',
              'body' => 'Coincidencias puntuadas.',
            ),
            3 => array(
              'icon' => 'calculator',
              'title' => 'Coste por trabajo',
              'body' => 'Proyecto correcto.',
            ),
            4 => array(
              'icon' => 'clock',
              'title' => 'Más rápido',
              'body' => 'Minutos, no horas.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Preciso',
              'body' => 'Libros limpios.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deja que la conciliación se haga sola.',
            'sub' => 'Las transacciones se emparejan con el proveedor y el trabajo correctos.',
          ),
        ),
        'retailer-scraping' => array(
          'icon' => 'globe-alt',
          'title' => 'Extracción de comercios',
          'body' => 'Extrae recibos detallados directamente de las cuentas de proveedores.',
          'hero' => 'Recibos detallados, extraídos por ti',
          'lead' => 'Conecta tus cuentas de proveedores y Hive extrae recibos detallados completos automáticamente, así obtienes el detalle por partida sin guardar ni un solo ticket.',
          'rows' => array(
            0 => array(
              'heading' => 'Directo de la fuente',
              'text' => 'Para las tiendas donde más compras, Hive toma el recibo detallado completo directo de tu cuenta: cada SKU, cantidad y precio.',
              'points' => array(
                0 => 'Extrae de cuentas de proveedores',
                1 => 'Detalle completo a nivel de SKU',
                2 => 'Sin guardar tickets de papel',
                3 => 'Nada se pierde',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Extraído · Home Depot',
                'rows' => array(
                  0 => array(
                    'icon' => 'globe-alt',
                    'label' => 'Pedido #4821',
                    'sub' => '14 artículos',
                  ),
                  1 => array(
                    'icon' => 'list-bullet',
                    'label' => 'Detalle de partidas',
                    'sub' => 'SKU y cant.',
                  ),
                  2 => array(
                    'icon' => 'folder',
                    'label' => 'Asignado',
                    'sub' => 'Maple St',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Mejor que una foto',
              'text' => 'Los recibos extraídos llevan detalles que una foto puede desvanecer o cortar, dándote registros más limpios, un coste por trabajo más ajustado y devoluciones más fáciles.',
              'points' => array(
                0 => 'Más detalle que una foto',
                1 => 'Registros permanentes más limpios',
                2 => 'Coste por trabajo más ajustado',
                3 => 'Devoluciones y garantías más fáciles',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'El detalle de un recibo de papel descolorido desaparece en meses. Extraerlo de la fuente lo conserva para siempre.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'globe-alt',
              'title' => 'De la fuente',
              'body' => 'Cuentas de proveedores.',
            ),
            1 => array(
              'icon' => 'list-bullet',
              'title' => 'Detalle de SKU',
              'body' => 'Cada partida.',
            ),
            2 => array(
              'icon' => 'bolt',
              'title' => 'Automático',
              'body' => 'Sin tickets.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Asignado',
              'body' => 'Al trabajo.',
            ),
            4 => array(
              'icon' => 'arrow-uturn-left',
              'title' => 'Devoluciones',
              'body' => 'Prueba fácil.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Permanente',
              'body' => 'Nunca se desvanece.',
            ),
          ),
          'cta' => array(
            'heading' => 'Obtén el recibo completo automáticamente.',
            'sub' => 'Detalle desglosado extraído de tus cuentas de proveedores.',
          ),
        ),
        'text-to-task' => array(
          'icon' => 'calendar-date-range',
          'title' => 'De mensaje a tarea',
          'body' => 'Convierte un mensaje entrante en una tarea programada, revisada primero.',
          'hero' => 'La IA convierte mensajes en trabajo programado',
          'lead' => 'Los mensajes entrantes que contienen algo por hacer se convierten en tareas borrador en tu agenda, escritas por la IA y revisadas por ti, para que nada se escape.',
          'rows' => array(
            0 => array(
              'heading' => 'La IA hace el tecleo',
              'text' => 'Un mensaje que menciona trabajo se convierte en una tarea borrador con el trabajo, la fecha y los detalles correctos ya rellenados. Solo la miras y confirmas.',
              'points' => array(
                0 => 'La IA lee los mensajes entrantes',
                1 => 'Redacta una tarea completa',
                2 => 'Trabajo, fecha y detalles definidos',
                3 => 'Confirmas con un toque',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Creado desde un mensaje',
                'rows' => array(
                  0 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Entrante',
                    'sub' => 'Reparar panel de yeso vie',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Tarea',
                    'sub' => 'Oak Ave · vie',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Confirmar',
                    'sub' => 'Un toque',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Siempre revisado primero',
              'text' => 'La IA nunca programa a tus espaldas. Cada tarea sugerida espera tu aprobación, así mantienes el control total de tu calendario.',
              'points' => array(
                0 => 'Nada se programa a ciegas',
                1 => 'Aprueba o edita primero',
                2 => 'Control total del calendario',
                3 => 'Automatización de confianza',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'La automatización en la que puedes confiar es la que puedes revisar. Hive propone; tú decides.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'sparkles',
              'title' => 'La IA redacta',
              'body' => 'Desde un mensaje.',
            ),
            1 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Programado',
              'body' => 'Fecha correcta.',
            ),
            2 => array(
              'icon' => 'check-circle',
              'title' => 'Revisado',
              'body' => 'Tú apruebas.',
            ),
            3 => array(
              'icon' => 'pencil',
              'title' => 'Editable',
              'body' => 'Ajusta antes.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'En la obra',
              'body' => 'Proyecto correcto.',
            ),
            5 => array(
              'icon' => 'bell-alert',
              'title' => 'Registrado',
              'body' => 'Nunca se pierde.',
            ),
          ),
          'cta' => array(
            'heading' => 'Convierte mensajes en trabajo programado.',
            'sub' => 'La IA redacta la tarea; tú confirmas con un toque.',
          ),
        ),
        'call-summaries' => array(
          'icon' => 'microphone',
          'title' => 'Resúmenes de llamadas',
          'body' => 'Cada llamada grabada se transcribe y resume con acciones a realizar.',
          'hero' => 'Cada llamada, resumida para ti',
          'lead' => 'Las llamadas grabadas se transcriben y condensan en un breve resumen con acciones a realizar, así captas lo esencial y los pendientes sin volver a reproducir nada.',
          'rows' => array(
            0 => array(
              'heading' => 'Las conclusiones, no la repetición',
              'text' => 'La IA convierte una llamada larga en unos pocos puntos claros y una lista de acciones, vinculados al cliente y la obra correctos, listos para actuar.',
              'points' => array(
                0 => 'Transcripción completa capturada',
                1 => 'Resumen breve y claro',
                2 => 'Acciones extraídas',
                3 => 'Vinculadas al cliente y la obra',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Resumen · Henderson',
                'rows' => array(
                  0 => array(
                    'icon' => 'sparkles',
                    'label' => 'Resumen',
                    'sub' => '3 puntos clave',
                  ),
                  1 => array(
                    'icon' => 'check-circle',
                    'label' => 'Acción',
                    'sub' => 'Enviar presupuesto de azulejos',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Acción',
                    'sub' => 'Confirmar inicio el lun.',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Nada se escapa tras una llamada',
              'text' => 'Las promesas y los siguientes pasos de una llamada se convierten en tareas accionables, así el trabajo acordado por teléfono realmente se hace.',
              'points' => array(
                0 => 'Las promesas se vuelven tareas',
                1 => 'Los siguientes pasos no se olvidan',
                2 => 'Cumplimiento cada vez',
                3 => 'Un registro en el que confiar',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'La mayoría de los descuidos empiezan en una llamada que nadie anotó. Los resúmenes con acciones cierran esa brecha.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-text',
              'title' => 'Transcrito',
              'body' => 'Texto completo.',
            ),
            1 => array(
              'icon' => 'sparkles',
              'title' => 'Resumido',
              'body' => 'Puntos clave.',
            ),
            2 => array(
              'icon' => 'check-circle',
              'title' => 'Acciones',
              'body' => 'Extraídas.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Adjunto',
              'body' => 'A la obra.',
            ),
            4 => array(
              'icon' => 'calendar-date-range',
              'title' => 'A tareas',
              'body' => 'Actúa.',
            ),
            5 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Buscable',
              'body' => 'Encuéntralo luego.',
            ),
          ),
          'cta' => array(
            'heading' => 'Capta lo esencial de cada llamada.',
            'sub' => 'Transcritas, resumidas y con acciones listas.',
          ),
        ),
        'maps-autocomplete' => array(
          'icon' => 'map-pin',
          'title' => 'Mapas y autocompletado',
          'body' => 'Autocompletado de direcciones y mapas de la obra integrados.',
          'hero' => 'Direcciones y mapas, integrados',
          'lead' => 'El autocompletado rellena direcciones de obra limpias y correctas mientras escribes, y los mapas integrados llevan a tu cuadrilla a la puerta correcta siempre.',
          'rows' => array(
            0 => array(
              'heading' => 'Direcciones correctas siempre',
              'text' => 'Empieza a escribir y elige la dirección verificada. Sin erratas, sin números de unidad equivocados, sin cuadrillas yendo a la calle incorrecta.',
              'points' => array(
                0 => 'Autocompletado al escribir',
                1 => 'Direcciones verificadas y estandarizadas',
                2 => 'Sin erratas ni unidades erróneas',
                3 => 'Registros consistentes',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Nueva obra',
                'rows' => array(
                  0 => array(
                    'icon' => 'map-pin',
                    'label' => 'Escrito',
                    'sub' => '142 Maple...',
                  ),
                  1 => array(
                    'icon' => 'check-badge',
                    'label' => 'Verificado',
                    'sub' => '142 Maple St',
                  ),
                  2 => array(
                    'icon' => 'map',
                    'label' => 'En el mapa',
                    'sub' => 'Ruta lista',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Las cuadrillas hallan la puerta',
              'text' => 'Cada obra lleva un mapa y una ruta, así tu cuadrilla llega rápido al sitio correcto: menos tiempo perdido, menos combustible gastado, menos inicios tardíos.',
              'points' => array(
                0 => 'Mapas en cada obra',
                1 => 'Ruta con un toque',
                2 => 'Menos viajes a la obra errónea',
                3 => 'Inicios puntuales',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Por qué importa',
                'note' => 'Una dirección equivocada es una mañana perdida. Las direcciones limpias y los mapas integrados mantienen a las cuadrillas en marcha.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'map-pin',
              'title' => 'Autocompletado',
              'body' => 'Al escribir.',
            ),
            1 => array(
              'icon' => 'check-badge',
              'title' => 'Verificado',
              'body' => 'Sin erratas.',
            ),
            2 => array(
              'icon' => 'map',
              'title' => 'Mapas integrados',
              'body' => 'En cada obra.',
            ),
            3 => array(
              'icon' => 'arrow-top-right-on-square',
              'title' => 'Rutas',
              'body' => 'Un toque.',
            ),
            4 => array(
              'icon' => 'clock',
              'title' => 'A tiempo',
              'body' => 'Llega rápido.',
            ),
            5 => array(
              'icon' => 'bolt',
              'title' => 'Menos combustible',
              'body' => 'Sin viajes en vano.',
            ),
          ),
          'cta' => array(
            'heading' => 'Lleva a las cuadrillas a la puerta correcta.',
            'sub' => 'Autocompletado de direcciones y mapas integrados.',
          ),
        ),
      ),
    ),
  ),
);
