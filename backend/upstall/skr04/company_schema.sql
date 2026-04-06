--
--

--
--

CREATE SCHEMA tax;

--
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;

--
--

--
--

CREATE TYPE public.custom_data_export_query_parameter_default_value_type_enum AS ENUM (
    'none',
    'current_user_login',
    'sql_query',
    'fixed_value'
);

--
--

CREATE TYPE public.custom_data_export_query_parameter_type_enum AS ENUM (
    'text',
    'number',
    'date',
    'timestamp'
);

--
--

CREATE TYPE public.datev_export_format_enum AS ENUM (
    'cp1252',
    'cp1252-translit',
    'utf-8'
);

--
--

CREATE TYPE public.delivery_order_types AS ENUM (
    'sales_delivery_order',
    'purchase_delivery_order',
    'supplier_delivery_order',
    'rma_delivery_order'
);

--
--

CREATE TYPE public.dunning_creator AS ENUM (
    'current_employee',
    'invoice_employee'
);

--
--

CREATE TYPE public.email_journal_record_type AS ENUM (
    'sales_order',
    'purchase_order',
    'sales_quotation',
    'request_quotation',
    'purchase_quotation_intake',
    'sales_order_intake',
    'sales_delivery_order',
    'purchase_delivery_order',
    'supplier_delivery_order',
    'rma_delivery_order',
    'sales_reclamation',
    'purchase_reclamation',
    'invoice',
    'invoice_for_advance_payment',
    'invoice_for_advance_payment_storno',
    'final_invoice',
    'invoice_storno',
    'credit_note',
    'credit_note_storno',
    'purchase_invoice',
    'purchase_credit_note',
    'ap_transaction',
    'ar_transaction',
    'gl_transaction',
    'purchase_order_confirmation',
    'catch_all'
);

--
--

CREATE TYPE public.email_journal_status AS ENUM (
    'sent',
    'send_failed',
    'imported'
);

--
--

CREATE TYPE public.file_object_types AS ENUM (
    'sales_quotation',
    'sales_order',
    'sales_order_intake',
    'request_quotation',
    'purchase_quotation_intake',
    'purchase_order',
    'purchase_order_confirmation',
    'sales_delivery_order',
    'supplier_delivery_order',
    'purchase_delivery_order',
    'rma_delivery_order',
    'invoice',
    'invoice_for_advance_payment',
    'final_invoice',
    'credit_note',
    'purchase_invoice',
    'sales_reclamation',
    'purchase_reclamation',
    'dunning',
    'dunning1',
    'dunning2',
    'dunning3',
    'dunning_orig_invoice',
    'dunning_invoice',
    'customer',
    'vendor',
    'gl_transaction',
    'part',
    'shop_image',
    'draft',
    'letter',
    'project',
    'statement'
);

--
--

CREATE TYPE public.files_backends AS ENUM (
    'Filesystem',
    'Webdav'
);

--
--

CREATE TYPE public.invoice_creation_mode AS ENUM (
    'create_new',
    'use_last_created_or_create_new'
);

--
--

CREATE TYPE public.invoice_mail_settings AS ENUM (
    'cp',
    'invoice_mail',
    'invoice_mail_cc_cp'
);

--
--

CREATE TYPE public.items_recurring_billing_mode AS ENUM (
    'never',
    'once',
    'always'
);

--
--

CREATE TYPE public.order_types AS ENUM (
    'request_quotation',
    'sales_quotation',
    'purchase_quotation_intake',
    'purchase_order',
    'sales_order_intake',
    'sales_order',
    'purchase_order_confirmation'
);

--
--

CREATE TYPE public.part_type_enum AS ENUM (
    'part',
    'service',
    'assembly',
    'assortment'
);

--
--

CREATE TYPE public.reclamation_types AS ENUM (
    'sales_reclamation',
    'purchase_reclamation'
);

--
--

CREATE TYPE public.record_template_type AS ENUM (
    'ar_transaction',
    'ap_transaction',
    'gl_transaction'
);

--
--

CREATE FUNCTION public.add_parts_price_history_entry() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    IF      (TG_OP = 'UPDATE')
        AND ((OLD.lastcost        IS NULL AND NEW.lastcost        IS NULL) OR (OLD.lastcost     = NEW.lastcost))
        AND ((OLD.listprice       IS NULL AND NEW.listprice       IS NULL) OR (OLD.listprice    = NEW.listprice))
        AND ((OLD.sellprice       IS NULL AND NEW.sellprice       IS NULL) OR (OLD.sellprice    = NEW.sellprice))
        AND ((OLD.price_factor_id IS NULL AND NEW.price_factor_id IS NULL) OR
             ( (SELECT factor FROM price_factors WHERE price_factors.id = OLD.price_factor_id) = (SELECT factor FROM price_factors WHERE price_factors.id = NEW.price_factor_id) ))
        THEN
      RETURN NEW;
    END IF;

    INSERT INTO parts_price_history (part_id, lastcost, listprice, sellprice, price_factor, valid_from)
    VALUES (NEW.id, NEW.lastcost, NEW.listprice, NEW.sellprice, COALESCE((SELECT factor FROM price_factors WHERE price_factors.id = NEW.price_factor_id), 1), now());

    RETURN NEW;
  END;
$$;

--
--

CREATE FUNCTION public.chart_category_to_sgn(character) RETURNS integer
    LANGUAGE sql
    AS $_$SELECT  1 WHERE $1 IN ('I', 'L', 'Q')
      UNION 
    SELECT -1 WHERE $1 IN ('E', 'A')$_$;

--
--

CREATE FUNCTION public.check_bin_belongs_to_wh() RETURNS trigger
    LANGUAGE plpgsql
    AS $$BEGIN
        IF NEW.bin_id IS NULL AND NEW.warehouse_id IS NULL THEN
          RETURN NEW;
        END IF;
        IF NEW.bin_id IN (SELECT id FROM bin WHERE warehouse_id = NEW.warehouse_id) THEN
          RETURN NEW;
        ELSE
          RAISE EXCEPTION 'bin (id=%) does not belong to warehouse (id=%).', NEW.bin_id, NEW.warehouse_id;
          RETURN NULL;
        END IF;
      END;$$;

--
--

CREATE FUNCTION public.clean_up_acc_trans_after_ar_ap_gl_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM acc_trans WHERE trans_id = OLD.id;
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_after_customer_vendor_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM contacts
    WHERE cp_cv_id = OLD.id;

    DELETE FROM shipto
    WHERE (trans_id = OLD.id)
      AND (module   = 'CT');

    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_ap_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'ap' AND from_id = OLD.id)
         OR (to_table   = 'ap' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_ar_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'ar' AND from_id = OLD.id)
         OR (to_table   = 'ar' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = TG_TABLE_NAME AND from_id = OLD.id)
         OR (to_table   = TG_TABLE_NAME AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_delivery_order_items_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'delivery_order_items' AND from_id = OLD.id)
         OR (to_table   = 'delivery_order_items' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_delivery_orders_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'delivery_orders' AND from_id = OLD.id)
         OR (to_table   = 'delivery_orders' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_dunning_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'dunning' AND from_id = OLD.id)
         OR (to_table   = 'dunning' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_gl_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'gl' AND from_id = OLD.id)
         OR (to_table   = 'gl' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_invoice_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'invoice' AND from_id = OLD.id)
         OR (to_table   = 'invoice' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_letter_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'letter' AND from_id = OLD.id)
         OR (to_table   = 'letter' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_oe_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'oe' AND from_id = OLD.id)
         OR (to_table   = 'oe' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.clean_up_record_links_before_orderitems_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM record_links
      WHERE (from_table = 'orderitems' AND from_id = OLD.id)
         OR (to_table   = 'orderitems' AND to_id   = OLD.id);
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.delete_custom_variables_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    IF (TG_TABLE_NAME IN ('orderitems', 'delivery_order_items', 'invoice', 'reclamation_items')) THEN
      PERFORM delete_custom_variables_with_sub_module('IC', TG_TABLE_NAME, old.id);
    END IF;

    IF (TG_TABLE_NAME = 'parts') THEN
      PERFORM delete_custom_variables_with_sub_module('IC', '', old.id);
    END IF;

    IF (TG_TABLE_NAME IN ('customer', 'vendor')) THEN
      PERFORM delete_custom_variables_with_sub_module('CT', '', old.id);
    END IF;

    IF (TG_TABLE_NAME = 'contacts') THEN
      PERFORM delete_custom_variables_with_sub_module('Contacts', '', old.cp_id);
    END IF;

    IF (TG_TABLE_NAME = 'project') THEN
      PERFORM delete_custom_variables_with_sub_module('Projects', '', old.id);
    END IF;

    IF (TG_TABLE_NAME = 'shipto') THEN
      PERFORM delete_custom_variables_with_sub_module('ShipTo', '', old.shipto_id);
    END IF;

    RETURN old;
  END;
$$;

--
--

CREATE FUNCTION public.delete_custom_variables_with_sub_module(config_module text, cvar_sub_module text, old_id integer) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM custom_variables
    WHERE EXISTS (SELECT id FROM custom_variable_configs cfg WHERE (cfg.module = config_module) AND (custom_variables.config_id = cfg.id))
      AND (COALESCE(sub_module, '') = cvar_sub_module)
      AND (trans_id                 = old_id);

    RETURN TRUE;
  END;
$$;

--
--

CREATE FUNCTION public.delete_requirement_spec_custom_variables_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM custom_variables WHERE (sub_module = '' OR sub_module IS NULL)
                                   AND trans_id = OLD.id
                                   AND (SELECT module FROM custom_variable_configs WHERE id = config_id) = 'RequirementSpecs';

    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.delivery_orders_before_delete_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        BEGIN
          DELETE FROM status                     WHERE trans_id = OLD.id;
          DELETE FROM delivery_order_items_stock WHERE delivery_order_item_id IN (SELECT id FROM delivery_order_items WHERE delivery_order_id = OLD.id);
          DELETE FROM shipto                     WHERE (trans_id = OLD.id) AND (module = 'OE');

          RETURN OLD;
        END;
      $$;

--
--

CREATE FUNCTION public.first_agg(anyelement, anyelement) RETURNS anyelement
    LANGUAGE sql IMMUTABLE STRICT
    AS $_$
  SELECT $1;
$_$;

--
--

CREATE FUNCTION public.follow_up_close_when_oe_closed_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    IF COALESCE(NEW.closed, FALSE) AND NOT COALESCE(OLD.closed, FALSE) THEN
      INSERT INTO follow_up_done (follow_up_id)
        SELECT follow_ups.id
        FROM follow_ups
        LEFT JOIN follow_up_done ON (follow_up_done.follow_up_id = follow_ups.id)
        WHERE follow_up_done.id IS NULL
          AND follow_ups.id IN (
          SELECT follow_up_id
          FROM follow_up_links
          WHERE (trans_id   = NEW.id)
            AND (trans_type IN ('sales_quotation',   'sales_order',    'sales_delivery_order',
                                'request_quotation', 'purchase_order', 'purchase_delivery_order'))
       );
    END IF;

    RETURN NEW;
  END;
$$;

--
--

CREATE FUNCTION public.follow_up_delete_notes_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM notes
    WHERE (trans_id     = OLD.id)
      AND (trans_module = 'fu');
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.follow_up_delete_when_customer_vendor_is_deleted_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM follow_ups
    WHERE id IN (
      SELECT follow_up_id
      FROM follow_up_links
      WHERE (trans_id   = OLD.id)
        AND (trans_type IN ('customer', 'vendor'))
    );

    DELETE FROM notes
    WHERE (trans_id     = OLD.id)
      AND (trans_module = 'ct');

    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.follow_up_delete_when_oe_is_deleted_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM follow_ups
    WHERE id IN (
      SELECT follow_up_id
      FROM follow_up_links
      WHERE (trans_id   = OLD.id)
        AND (trans_type IN ('sales_quotation',   'sales_order',    'sales_delivery_order',    'sales_invoice',
                            'request_quotation', 'purchase_order', 'purchase_delivery_order', 'purchase_invoice'))
    );

    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.generic_translations_delete_on_delivery_terms_delete_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM generic_translations
      WHERE translation_id = OLD.id AND translation_type LIKE 'SL::DB::DeliveryTerm/description_long';
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.generic_translations_delete_on_payment_terms_delete_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM generic_translations
    WHERE (translation_id = OLD.id)
      AND (translation_type IN ('SL::DB::PaymentTerm/description_long', 'SL::DB::PaymentTerm/description_long_invoice'));
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.generic_translations_delete_on_tax_delete_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    DELETE FROM generic_translations
      WHERE translation_id = OLD.id AND translation_type LIKE 'SL::DB::Tax/taxdescription';
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.oe_before_delete_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        BEGIN
          DELETE FROM status WHERE trans_id = OLD.id;
          DELETE FROM shipto WHERE (trans_id = OLD.id) AND (module = 'OE');

          RETURN OLD;
        END;
      $$;

--
--

CREATE FUNCTION public.recalculate_all_spec_item_time_estimations() RETURNS boolean
    LANGUAGE plpgsql
    AS $$
  DECLARE
    rspec RECORD;
  BEGIN
    FOR rspec IN SELECT id FROM requirement_specs LOOP
      PERFORM recalculate_spec_item_time_estimation(rspec.id);
    END LOOP;

    RETURN TRUE;
  END;
$$;

--
--

CREATE FUNCTION public.recalculate_spec_item_time_estimation(the_requirement_spec_id integer) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
  DECLARE
    item RECORD;
  BEGIN
    FOR item IN
      SELECT DISTINCT parent_id
      FROM requirement_spec_items
      WHERE (requirement_spec_id = the_requirement_spec_id)
        AND (item_type           = 'sub-function-block')
    LOOP
      RAISE DEBUG 'hmm function-block with sub: %', item.parent_id;
      PERFORM update_requirement_spec_item_time_estimation(item.parent_id, the_requirement_spec_id);
    END LOOP;

    FOR item IN
      SELECT DISTINCT parent_id
      FROM requirement_spec_items
      WHERE (requirement_spec_id = the_requirement_spec_id)
        AND (item_type           = 'function-block')
        AND (id NOT IN (
          SELECT parent_id
          FROM requirement_spec_items
          WHERE (requirement_spec_id = the_requirement_spec_id)
            AND (item_type           = 'sub-function-block')
        ))
    LOOP
      RAISE DEBUG 'hmm section with function-block: %', item.parent_id;
      PERFORM update_requirement_spec_item_time_estimation(item.parent_id, the_requirement_spec_id);
    END LOOP;

    PERFORM update_requirement_spec_item_time_estimation(NULL, the_requirement_spec_id);

    RETURN TRUE;
  END;
$$;

--
--

CREATE FUNCTION public.requirement_spec_delete_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    IF TG_WHEN = 'AFTER' THEN
      DELETE FROM trigger_information WHERE (key = 'deleting_requirement_spec') AND (value = CAST(OLD.id AS TEXT));

      RETURN OLD;
    END IF;

    RAISE DEBUG 'before delete trigger on %', OLD.id;

    INSERT INTO trigger_information (key, value) VALUES ('deleting_requirement_spec', CAST(OLD.id AS TEXT));

    RAISE DEBUG '  Converting items into sections items for %', OLD.id;
    UPDATE requirement_spec_items SET item_type  = 'section', parent_id = NULL WHERE requirement_spec_id = OLD.id;

    RAISE DEBUG '  And we out for %', OLD.id;

    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.requirement_spec_item_before_delete_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    RAISE DEBUG 'delete trig RSitem old id %', OLD.id;
    INSERT INTO trigger_information (key, value) VALUES ('deleting_requirement_spec_item', CAST(OLD.id AS TEXT));
    DELETE FROM requirement_spec_items WHERE (parent_id         = OLD.id);
    DELETE FROM trigger_information    WHERE (key = 'deleting_requirement_spec_item') AND (value = CAST(OLD.id AS TEXT));
    RAISE DEBUG 'delete trig END %', OLD.id;
    RETURN OLD;
  END;
$$;

--
--

CREATE FUNCTION public.requirement_spec_item_time_estimation_updater_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  DECLARE
    do_new BOOLEAN;
  BEGIN
    RAISE DEBUG 'updateRSITE op %', TG_OP;
    IF ((TG_OP = 'UPDATE') OR (TG_OP = 'DELETE')) THEN
      RAISE DEBUG 'UPDATE trigg op % OLD.id % OLD.parent_id %', TG_OP, OLD.id, OLD.parent_id;
      PERFORM update_requirement_spec_item_time_estimation(OLD.parent_id, OLD.requirement_spec_id);
      RAISE DEBUG 'UPDATE trigg op % END %', TG_OP, OLD.id;
    END IF;
    do_new = FALSE;

    IF (TG_OP = 'UPDATE') THEN
      do_new = OLD.parent_id <> NEW.parent_id;
    END IF;

    IF (do_new OR (TG_OP = 'INSERT')) THEN
      RAISE DEBUG 'UPDATE trigg op % NEW.id % NEW.parent_id %', TG_OP, NEW.id, NEW.parent_id;
      PERFORM update_requirement_spec_item_time_estimation(NEW.parent_id, NEW.requirement_spec_id);
      RAISE DEBUG 'UPDATE trigg op % END %', TG_OP, NEW.id;
    END IF;

    RETURN NULL;
  END;
$$;

--
--

CREATE FUNCTION public.set_mtime() RETURNS trigger
    LANGUAGE plpgsql
    AS $$    BEGIN        NEW.mtime := 'now';        RETURN NEW;    END;$$;

--
--

CREATE FUNCTION public.shop_images_reorder_position() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  UPDATE shop_images
  SET position = reordered.new_position
  FROM (
    SELECT id, rank() OVER (PARTITION BY object_id ORDER BY position ASC) AS new_position
    FROM shop_images
    WHERE shop_images.object_id = OLD.object_id
  ) reordered
  WHERE shop_images.id = reordered.id
  AND shop_images.position IS DISTINCT FROM reordered.new_position;

  RETURN OLD;
END;
$$;

--
--

CREATE FUNCTION public.time_recordings_set_date_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    IF NEW.start_time IS NOT NULL THEN
      NEW.date = NEW.start_time::DATE;
    END IF;
    RETURN NEW;
  END;
$$;

--
--

CREATE FUNCTION public.time_recordings_set_duration_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
  BEGIN
    IF NEW.start_time IS NOT NULL AND NEW.end_time IS NOT NULL THEN
      NEW.duration = EXTRACT(EPOCH FROM (NEW.end_time - NEW.start_time))/60;
    END IF;
    RETURN NEW;
  END;
$$;

--
--

CREATE FUNCTION public.update_onhand() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF tg_op = 'INSERT' THEN
    UPDATE parts SET onhand = COALESCE(onhand, 0) + new.qty WHERE id = new.parts_id;
    RETURN new;
  ELSIF tg_op = 'DELETE' THEN
    UPDATE parts SET onhand = COALESCE(onhand, 0) - old.qty WHERE id = old.parts_id;
    RETURN old;
  ELSE
    UPDATE parts SET onhand = COALESCE(onhand, 0) - old.qty + new.qty WHERE id = old.parts_id;
    RETURN new;
  END IF;
END;
$$;

--
--

CREATE FUNCTION public.update_requirement_spec_item_time_estimation(item_id integer, item_requirement_spec_id integer) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
  DECLARE
    current_row RECORD;
    new_row     RECORD;
  BEGIN
    IF EXISTS(
      SELECT *
      FROM trigger_information
      WHERE ((key = 'deleting_requirement_spec_item') AND (value = CAST(item_id                  AS TEXT)))
         OR ((key = 'deleting_requirement_spec')      AND (value = CAST(item_requirement_spec_id AS TEXT)))
      LIMIT 1
    ) THEN
      RAISE DEBUG 'updateRSIE: item_id % or requirement_spec_id % is about to be deleted; do not update', item_id, item_requirement_spec_id;
      RETURN FALSE;
    END IF;

    -- item_id IS NULL means that a section has been updated. The
    -- requirement spec itself must therefore be updated.
    IF item_id IS NULL THEN
      SELECT COALESCE(time_estimation, 0) AS time_estimation
      INTO current_row
      FROM requirement_specs
      WHERE id = item_requirement_spec_id;

      SELECT COALESCE(SUM(time_estimation), 0) AS time_estimation
      INTO new_row
      FROM requirement_spec_items
      WHERE (parent_id IS NULL)
        AND (requirement_spec_id = item_requirement_spec_id);

      IF current_row.time_estimation <> new_row.time_estimation THEN
        RAISE DEBUG 'updateRSIE: updating requirement_spec % itself: old estimation % new %.', item_requirement_spec_id, current_row.time_estimation, new_row.time_estimation;

        UPDATE requirement_specs
        SET time_estimation = new_row.time_estimation
        WHERE id = item_requirement_spec_id;
      END IF;

      RETURN TRUE;
    END IF;

    -- If we're here it means that either a sub-function-block or a
    -- function-block has been updated. item_id is the parent's ID of
    -- the updated item -- meaning the ID of the item that needs to be
    -- updated now.

    SELECT COALESCE(time_estimation, 0) AS time_estimation
    INTO current_row
    FROM requirement_spec_items
    WHERE id = item_id;

    SELECT COALESCE(SUM(time_estimation), 0) AS time_estimation
    INTO new_row
    FROM requirement_spec_items
    WHERE (parent_id = item_id);

    IF current_row.time_estimation = new_row.time_estimation THEN
      RAISE DEBUG 'updateRSIE: item %: nothing to do', item_id;
      RETURN TRUE;
    END IF;

    RAISE DEBUG 'updateRSIE: updating item %: old estimation % new %.', item_id, current_row.time_estimation, new_row.time_estimation;

    UPDATE requirement_spec_items
    SET time_estimation = new_row.time_estimation
    WHERE id = item_id;

    RETURN TRUE;
  END;
$$;

--
--

CREATE AGGREGATE public.first(anyelement) (
    SFUNC = public.first_agg,
    STYPE = anyelement
);

--
--

CREATE SEQUENCE public.acc_trans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.acc_trans (
    acc_trans_id bigint DEFAULT nextval('public.acc_trans_id_seq'::regclass) NOT NULL,
    trans_id integer NOT NULL,
    chart_id integer NOT NULL,
    amount numeric(15,5),
    transdate date DEFAULT ('now'::text)::date,
    gldate date DEFAULT ('now'::text)::date,
    source text,
    cleared boolean DEFAULT false NOT NULL,
    fx_transaction boolean DEFAULT false NOT NULL,
    ob_transaction boolean DEFAULT false NOT NULL,
    cb_transaction boolean DEFAULT false NOT NULL,
    project_id integer,
    memo text,
    taxkey integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    chart_link text NOT NULL,
    tax_id integer NOT NULL
);

--
--

CREATE TABLE public.additional_billing_addresses (
    id integer NOT NULL,
    customer_id integer,
    name text,
    department_1 text,
    department_2 text,
    contact text,
    street text,
    zipcode text,
    city text,
    country text,
    gln text,
    email text,
    phone text,
    fax text,
    default_address boolean DEFAULT false NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone DEFAULT now() NOT NULL,
    dunning_mail text
);

--
--

CREATE SEQUENCE public.additional_billing_addresses_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.additional_billing_addresses_id_seq OWNED BY public.additional_billing_addresses.id;

--
--

CREATE TABLE public.ap (
    id integer DEFAULT nextval(('glid'::text)::regclass) NOT NULL,
    invnumber text NOT NULL,
    transdate date DEFAULT ('now'::text)::date,
    gldate date DEFAULT ('now'::text)::date,
    vendor_id integer,
    taxincluded boolean DEFAULT false,
    amount numeric(15,5) DEFAULT 0 NOT NULL,
    netamount numeric(15,5) DEFAULT 0 NOT NULL,
    paid numeric(15,5) DEFAULT 0 NOT NULL,
    datepaid date,
    duedate date,
    invoice boolean DEFAULT false,
    ordnumber text,
    notes text,
    employee_id integer,
    quonumber text,
    intnotes text,
    department_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    shipvia text,
    cp_id integer,
    language_id integer,
    payment_id integer,
    storno boolean DEFAULT false,
    taxzone_id integer NOT NULL,
    type text,
    orddate date,
    quodate date,
    globalproject_id integer,
    storno_id integer,
    transaction_description text,
    direct_debit boolean DEFAULT false,
    deliverydate date,
    delivery_term_id integer,
    currency_id integer NOT NULL,
    tax_point date,
    exchangerate numeric(15,5),
    qrbill_data text,
    is_sepa_blocked boolean DEFAULT false
);

--
--

CREATE TABLE public.ap_gl (
    ap_id integer NOT NULL,
    gl_id integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.ar (
    id integer DEFAULT nextval(('glid'::text)::regclass) NOT NULL,
    invnumber text NOT NULL,
    transdate date DEFAULT ('now'::text)::date,
    gldate date DEFAULT ('now'::text)::date,
    customer_id integer,
    taxincluded boolean,
    amount numeric(15,5) DEFAULT 0 NOT NULL,
    netamount numeric(15,5) DEFAULT 0 NOT NULL,
    paid numeric(15,5) DEFAULT 0 NOT NULL,
    datepaid date,
    duedate date,
    deliverydate date,
    invoice boolean DEFAULT false,
    shippingpoint text,
    notes text,
    ordnumber text,
    employee_id integer,
    quonumber text,
    cusordnumber text,
    intnotes text,
    department_id integer,
    shipvia text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    cp_id integer,
    language_id integer,
    payment_id integer,
    delivery_customer_id integer,
    delivery_vendor_id integer,
    storno boolean DEFAULT false,
    taxzone_id integer NOT NULL,
    shipto_id integer,
    type text,
    dunning_config_id integer,
    orddate date,
    quodate date,
    globalproject_id integer,
    salesman_id integer,
    marge_total numeric(15,5),
    marge_percent numeric(15,5),
    storno_id integer,
    transaction_description text,
    donumber text,
    invnumber_for_credit_note text,
    direct_debit boolean DEFAULT false,
    delivery_term_id integer,
    currency_id integer NOT NULL,
    tax_point date,
    qrbill_without_amount boolean DEFAULT false,
    billing_address_id integer,
    qr_reference text,
    exchangerate numeric(15,5),
    qr_unstructured_message text
);

--
--

CREATE SEQUENCE public.assembly_assembly_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.assembly (
    id integer NOT NULL,
    parts_id integer NOT NULL,
    qty real,
    bom boolean,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    assembly_id integer DEFAULT nextval('public.assembly_assembly_id_seq'::regclass) NOT NULL,
    "position" integer
);

--
--

CREATE TABLE public.assortment_items (
    assortment_id integer NOT NULL,
    parts_id integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    qty real NOT NULL,
    "position" integer NOT NULL,
    unit character varying(20) NOT NULL,
    charge boolean DEFAULT true
);

--
--

CREATE TABLE public.background_job_histories (
    id integer NOT NULL,
    package_name character varying(255),
    run_at timestamp without time zone,
    status character varying(255),
    result text,
    error text,
    data text,
    description text
);

--
--

CREATE SEQUENCE public.background_job_histories_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.background_job_histories_id_seq OWNED BY public.background_job_histories.id;

--
--

CREATE TABLE public.background_jobs (
    id integer NOT NULL,
    type character varying(255),
    package_name character varying(255),
    last_run_at timestamp without time zone,
    next_run_at timestamp without time zone,
    data text,
    active boolean,
    cron_spec character varying(255),
    node_id text,
    description text
);

--
--

CREATE SEQUENCE public.background_jobs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.background_jobs_id_seq OWNED BY public.background_jobs.id;

--
--

CREATE SEQUENCE public.id
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1;

--
--

CREATE TABLE public.bank_accounts (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    account_number character varying(100),
    bank_code character varying(100),
    iban character varying(100),
    bic character varying(100),
    bank text,
    chart_id integer NOT NULL,
    name text,
    reconciliation_starting_date date,
    reconciliation_starting_balance numeric(15,5),
    obsolete boolean DEFAULT false NOT NULL,
    sortkey integer NOT NULL,
    use_for_zugferd boolean DEFAULT false NOT NULL,
    use_for_qrbill boolean DEFAULT false NOT NULL,
    bank_account_id character varying,
    use_with_bank_import boolean DEFAULT true NOT NULL,
    qr_iban text
);

--
--

CREATE TABLE public.bank_transaction_acc_trans (
    bank_transaction_id integer NOT NULL,
    acc_trans_id bigint NOT NULL,
    ar_id integer,
    ap_id integer,
    gl_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.bank_transaction_acc_trans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.bank_transactions (
    id integer NOT NULL,
    transaction_id integer,
    remote_bank_code text,
    remote_account_number text,
    transdate date NOT NULL,
    valutadate date NOT NULL,
    amount numeric(15,5) NOT NULL,
    remote_name text,
    purpose text,
    invoice_amount numeric(15,5) DEFAULT 0,
    local_bank_account_id integer NOT NULL,
    currency_id integer NOT NULL,
    cleared boolean DEFAULT false NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    transaction_code text,
    transaction_text text,
    qr_reference text,
    exchangerate numeric(15,5),
    end_to_end_id text,
    remote_iban varchar(40),
    remote_bic varchar(20),
    primanota varchar(20),
    booking_key varchar(10),
    mandate_reference varchar(64),
    creditor_id varchar(64),
    fints_import_id integer,
    match_status varchar(20) DEFAULT 'unmatched',
    CONSTRAINT bank_transactions_check CHECK ((abs(invoice_amount) <= abs(amount)))
);

--
--

CREATE SEQUENCE public.bank_transactions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.bank_transactions_id_seq OWNED BY public.bank_transactions.id;

--
--

CREATE TABLE public.bin (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    warehouse_id integer NOT NULL,
    description text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.buchungsgruppen (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    description text,
    inventory_accno_id integer NOT NULL,
    sortkey integer NOT NULL,
    obsolete boolean DEFAULT false NOT NULL
);

--
--

CREATE TABLE public.business (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    description text,
    discount real,
    customernumberinit text,
    salesman boolean DEFAULT false,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.business_models (
    parts_id integer NOT NULL,
    business_id integer NOT NULL,
    model text,
    part_description text,
    part_longdescription text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    "position" integer
);

--
--

CREATE VIEW public.bwa_categories AS
 SELECT "*VALUES*".column1 AS id,
    "*VALUES*".column2 AS description
   FROM (VALUES (1,'Umsatzerlöse'::text), (2,'Best.Verdg.FE/UE'::text), (3,'Aktiv.Eigenleistung'::text), (4,'Mat./Wareneinkauf'::text), (5,'So.betr.Erlöse'::text), (10,'Personalkosten'::text), (11,'Raumkosten'::text), (12,'Betriebl.Steuern'::text), (13,'Vers./Beiträge'::text), (14,'Kfz.Kosten o.St.'::text), (15,'Werbe-Reisek.'::text), (16,'Kosten Warenabgabe'::text), (17,'Abschreibungen'::text), (18,'Rep./instandhlt.'::text), (19,'Übrige Steuern'::text), (20,'Sonst.Kosten'::text), (30,'Zinsaufwand'::text), (31,'Sonst.neutr.Aufw.'::text), (32,'Zinserträge'::text), (33,'Sonst.neutr.Ertrag'::text), (34,'Verr.kalk.Kosten'::text), (35,'Steuern Eink.u.Ertr.'::text)) "*VALUES*";

--
--

CREATE TABLE public.chart (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    accno text NOT NULL,
    description text,
    charttype character(1) DEFAULT 'A'::bpchar,
    category character(1),
    link text NOT NULL,
    taxkey_id integer,
    pos_bwa integer,
    pos_bilanz integer,
    pos_eur integer,
    datevautomatik boolean DEFAULT false,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    new_chart_id integer,
    valid_from date,
    pos_er integer,
    invalid boolean DEFAULT false
);

--
--

CREATE TABLE public.contact_departments (
    id integer NOT NULL,
    description text NOT NULL
);

--
--

CREATE SEQUENCE public.contact_departments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.contact_departments_id_seq OWNED BY public.contact_departments.id;

--
--

CREATE TABLE public.contact_titles (
    id integer NOT NULL,
    description text NOT NULL
);

--
--

CREATE SEQUENCE public.contact_titles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.contact_titles_id_seq OWNED BY public.contact_titles.id;

--
--

CREATE TABLE public.contacts (
    cp_id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    cp_cv_id integer,
    cp_title text,
    cp_givenname text,
    cp_name text,
    cp_email text,
    cp_phone1 text,
    cp_phone2 text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    cp_fax text,
    cp_mobile1 text,
    cp_mobile2 text,
    cp_satphone text,
    cp_satfax text,
    cp_project text,
    cp_privatphone text,
    cp_privatemail text,
    cp_abteilung text,
    cp_gender character(1),
    cp_street text,
    cp_zipcode text,
    cp_city text,
    cp_birthday date,
    cp_position text,
    cp_main boolean DEFAULT false
);

--
--

CREATE TABLE public.csv_import_profile_settings (
    id integer NOT NULL,
    csv_import_profile_id integer NOT NULL,
    key text NOT NULL,
    value text
);

--
--

CREATE SEQUENCE public.csv_import_profile_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.csv_import_profile_settings_id_seq OWNED BY public.csv_import_profile_settings.id;

--
--

CREATE TABLE public.csv_import_profiles (
    id integer NOT NULL,
    name text NOT NULL,
    type character varying(20) NOT NULL,
    is_default boolean DEFAULT false,
    login text
);

--
--

CREATE SEQUENCE public.csv_import_profiles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.csv_import_profiles_id_seq OWNED BY public.csv_import_profiles.id;

--
--

CREATE TABLE public.csv_import_report_rows (
    id integer NOT NULL,
    csv_import_report_id integer NOT NULL,
    col integer NOT NULL,
    "row" integer NOT NULL,
    value text
);

--
--

CREATE SEQUENCE public.csv_import_report_rows_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.csv_import_report_rows_id_seq OWNED BY public.csv_import_report_rows.id;

--
--

CREATE TABLE public.csv_import_report_status (
    id integer NOT NULL,
    csv_import_report_id integer NOT NULL,
    "row" integer NOT NULL,
    type text NOT NULL,
    value text
);

--
--

CREATE SEQUENCE public.csv_import_report_status_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.csv_import_report_status_id_seq OWNED BY public.csv_import_report_status.id;

--
--

CREATE TABLE public.csv_import_reports (
    id integer NOT NULL,
    session_id text NOT NULL,
    profile_id integer NOT NULL,
    type text NOT NULL,
    file text NOT NULL,
    numrows integer NOT NULL,
    numheaders integer NOT NULL,
    test_mode boolean NOT NULL
);

--
--

CREATE SEQUENCE public.csv_import_reports_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.csv_import_reports_id_seq OWNED BY public.csv_import_reports.id;

--
--

CREATE TABLE public.currencies (
    id integer NOT NULL,
    name text NOT NULL
);

--
--

CREATE SEQUENCE public.currencies_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.currencies_id_seq OWNED BY public.currencies.id;

--
--

CREATE TABLE public.custom_data_export_queries (
    id integer NOT NULL,
    name text NOT NULL,
    description text NOT NULL,
    sql_query text NOT NULL,
    access_right text,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone DEFAULT now() NOT NULL
);

--
--

CREATE SEQUENCE public.custom_data_export_queries_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.custom_data_export_queries_id_seq OWNED BY public.custom_data_export_queries.id;

--
--

CREATE TABLE public.custom_data_export_query_parameters (
    id integer NOT NULL,
    query_id integer NOT NULL,
    name text NOT NULL,
    description text,
    parameter_type public.custom_data_export_query_parameter_type_enum NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone DEFAULT now() NOT NULL,
    default_value_type public.custom_data_export_query_parameter_default_value_type_enum NOT NULL,
    default_value text
);

--
--

CREATE SEQUENCE public.custom_data_export_query_parameters_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.custom_data_export_query_parameters_id_seq OWNED BY public.custom_data_export_query_parameters.id;

--
--

CREATE TABLE public.custom_variable_config_partsgroups (
    custom_variable_config_id integer NOT NULL,
    partsgroup_id integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.custom_variable_configs_id
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.custom_variable_configs (
    id integer DEFAULT nextval('public.custom_variable_configs_id'::regclass) NOT NULL,
    name text NOT NULL,
    description text NOT NULL,
    type text NOT NULL,
    module text NOT NULL,
    default_value text,
    options text,
    searchable boolean NOT NULL,
    includeable boolean NOT NULL,
    included_by_default boolean NOT NULL,
    sortkey integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    flags text,
    first_tab boolean DEFAULT false NOT NULL,
    CONSTRAINT custom_variable_configs_name_description_type_module_not_empty CHECK (((type <> ''::text) AND (module <> ''::text) AND (name <> ''::text) AND (description <> ''::text))),
    CONSTRAINT custom_variable_configs_options_not_empty_for_select CHECK (((type <> 'select'::text) OR (COALESCE(options, ''::text) <> ''::text)))
);

--
--

CREATE SEQUENCE public.custom_variables_id
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.custom_variables (
    id integer DEFAULT nextval('public.custom_variables_id'::regclass) NOT NULL,
    config_id integer NOT NULL,
    trans_id integer NOT NULL,
    bool_value boolean,
    timestamp_value timestamp without time zone,
    text_value text,
    number_value numeric(25,5),
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    sub_module text DEFAULT ''::text NOT NULL
);

--
--

CREATE TABLE public.custom_variables_validity (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    config_id integer NOT NULL,
    trans_id integer NOT NULL,
    itime timestamp without time zone DEFAULT now()
);

--
--

CREATE TABLE public.customer (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    name text NOT NULL,
    department_1 text,
    department_2 text,
    street text,
    zipcode text,
    city text,
    country text,
    contact text,
    phone text,
    fax text,
    homepage text,
    email text,
    notes text,
    discount real,
    taxincluded boolean,
    creditlimit numeric(15,5) DEFAULT 0,
    customernumber text,
    cc text,
    bcc text,
    business_id integer,
    taxnumber text,
    account_number text,
    bank_code text,
    bank text,
    language text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    obsolete boolean DEFAULT false,
    username text,
    user_password text,
    salesman_id integer,
    c_vendor_id text,
    language_id integer,
    payment_id integer,
    taxzone_id integer NOT NULL,
    greeting text,
    ustid text,
    iban text,
    bic text,
    direct_debit boolean DEFAULT false,
    depositor text,
    taxincluded_checked boolean,
    mandator_id text,
    mandate_date_of_signature date,
    delivery_term_id integer,
    hourly_rate numeric(8,2),
    currency_id integer NOT NULL,
    gln text,
    pricegroup_id integer,
    order_lock boolean DEFAULT false,
    commercial_court text,
    invoice_mail text,
    contact_origin text,
    delivery_order_mail text,
    create_zugferd_invoices integer DEFAULT '-1'::integer NOT NULL,
    natural_person boolean DEFAULT false,
    c_vendor_routing_id text,
    postal_invoice boolean DEFAULT false,
    dunning_mail text,
    dunning_lock boolean DEFAULT false NOT NULL
);

--
--

CREATE TABLE public.datev (
    beraternr character varying(7),
    beratername character varying(9),
    mandantennr character varying(5),
    dfvkz character varying(2),
    datentraegernr character varying(3),
    abrechnungsnr character varying(6),
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.datev_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.datev_id_seq OWNED BY public.datev.id;

--
--

CREATE TABLE public.defaults (
    inventory_accno_id integer,
    income_accno_id integer,
    expense_accno_id integer,
    fxgain_accno_id integer,
    fxloss_accno_id integer,
    invnumber text,
    sonumber text,
    weightunit character varying(5),
    businessnumber text,
    version character varying(8),
    closedto date,
    revtrans boolean DEFAULT false,
    ponumber text,
    sqnumber text,
    rfqnumber text,
    customernumber text,
    vendornumber text,
    articlenumber text,
    servicenumber text,
    coa text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    rmanumber text,
    cnnumber text,
    accounting_method text,
    inventory_system text,
    profit_determination text,
    dunning_ar_amount_fee integer,
    dunning_ar_amount_interest integer,
    dunning_ar integer,
    stocktaking_warehouse_id integer,
    stocktaking_bin_id integer,
    stocktaking_cutoff_date date,
    pdonumber text,
    sdonumber text,
    stocktaking_qty_threshold numeric(25,5) DEFAULT 0,
    ar_paid_accno_id integer,
    id integer NOT NULL,
    language_id integer,
    datev_check_on_sales_invoice boolean DEFAULT true,
    datev_check_on_purchase_invoice boolean DEFAULT true,
    datev_check_on_ar_transaction boolean DEFAULT true,
    datev_check_on_ap_transaction boolean DEFAULT true,
    datev_check_on_gl_transaction boolean DEFAULT true,
    payments_changeable integer DEFAULT 0 NOT NULL,
    is_changeable integer DEFAULT 2 NOT NULL,
    ir_changeable integer DEFAULT 2 NOT NULL,
    ar_changeable integer DEFAULT 2 NOT NULL,
    ap_changeable integer DEFAULT 2 NOT NULL,
    gl_changeable integer DEFAULT 2 NOT NULL,
    show_bestbefore boolean DEFAULT false,
    sales_order_show_delete boolean DEFAULT true,
    purchase_order_show_delete boolean DEFAULT true,
    sales_delivery_order_show_delete boolean DEFAULT true,
    purchase_delivery_order_show_delete boolean DEFAULT true,
    is_show_mark_as_paid boolean DEFAULT true,
    ir_show_mark_as_paid boolean DEFAULT true,
    ar_show_mark_as_paid boolean DEFAULT true,
    ap_show_mark_as_paid boolean DEFAULT true,
    warehouse_id integer,
    bin_id integer,
    company text,
    taxnumber text,
    co_ustid text,
    duns text,
    sepa_creditor_id text,
    templates text,
    max_future_booking_interval integer DEFAULT 360,
    "precision" numeric(15,5) DEFAULT 0.01 NOT NULL,
    webdav boolean DEFAULT false,
    webdav_documents boolean DEFAULT false,
    parts_show_image boolean DEFAULT true,
    parts_listing_image boolean DEFAULT true,
    parts_image_css text DEFAULT 'border:0;float:left;max-width:250px;margin-top:20px:margin-right:10px;margin-left:10px;'::text,
    normalize_vc_names boolean DEFAULT true,
    normalize_part_descriptions boolean DEFAULT true,
    assemblynumber text,
    show_weight boolean DEFAULT false NOT NULL,
    transfer_default boolean DEFAULT true,
    transfer_default_use_master_default_bin boolean DEFAULT false,
    transfer_default_ignore_onhand boolean DEFAULT false,
    warehouse_id_ignore_onhand integer,
    bin_id_ignore_onhand integer,
    balance_startdate_method text,
    currency_id integer NOT NULL,
    customer_hourly_rate numeric(8,2),
    signature text,
    requirement_spec_section_order_part_id integer,
    transfer_default_services boolean DEFAULT true,
    rndgain_accno_id integer,
    rndloss_accno_id integer,
    global_bcc text DEFAULT ''::text,
    customer_projects_only_in_sales boolean DEFAULT false NOT NULL,
    reqdate_interval integer DEFAULT 0,
    require_transaction_description_ps boolean DEFAULT false NOT NULL,
    sales_purchase_order_ship_missing_column boolean DEFAULT false,
    allow_sales_invoice_from_sales_quotation boolean DEFAULT true NOT NULL,
    allow_sales_invoice_from_sales_order boolean DEFAULT true NOT NULL,
    allow_new_purchase_delivery_order boolean DEFAULT true NOT NULL,
    allow_new_purchase_invoice boolean DEFAULT true NOT NULL,
    disabled_price_sources text[],
    bcc_to_login boolean DEFAULT false NOT NULL,
    transport_cost_reminder_article_number_id integer,
    is_transfer_out boolean DEFAULT false NOT NULL,
    ap_chart_id integer NOT NULL,
    ar_chart_id integer NOT NULL,
    create_part_if_not_found boolean DEFAULT false,
    letternumber integer,
    order_always_project boolean DEFAULT false,
    project_status_id integer,
    project_type_id integer,
    feature_balance boolean DEFAULT true NOT NULL,
    feature_datev boolean DEFAULT true NOT NULL,
    feature_erfolgsrechnung boolean DEFAULT false NOT NULL,
    feature_eurechnung boolean DEFAULT true NOT NULL,
    feature_ustva boolean DEFAULT true NOT NULL,
    order_warn_duplicate_parts boolean DEFAULT true,
    show_longdescription_select_item boolean DEFAULT false,
    email_journal integer DEFAULT 2,
    quick_search_modules text[],
    fa_bufa_nr text,
    fa_dauerfrist text,
    fa_steuerberater_city text,
    fa_steuerberater_name text,
    fa_steuerberater_street text,
    fa_steuerberater_tel text,
    fa_voranmeld text,
    doc_delete_printfiles boolean DEFAULT false,
    doc_max_filesize integer DEFAULT 10000000,
    doc_storage boolean DEFAULT false,
    doc_storage_for_documents text DEFAULT 'Filesystem'::text,
    doc_storage_for_attachments text DEFAULT 'Filesystem'::text,
    doc_storage_for_images text DEFAULT 'Filesystem'::text,
    doc_files boolean DEFAULT false,
    doc_files_rootpath text DEFAULT './documents'::text,
    doc_webdav boolean DEFAULT false,
    shipped_qty_require_stock_out boolean DEFAULT false NOT NULL,
    sepa_reference_add_vc_vc_id boolean DEFAULT false,
    assortmentnumber text,
    doc_storage_for_shopimages text DEFAULT 'Filesystem'::text,
    datev_export_format public.datev_export_format_enum DEFAULT 'cp1252-translit'::public.datev_export_format_enum,
    order_warn_no_deliverydate boolean DEFAULT true,
    sepa_set_duedate_as_default_exec_date boolean DEFAULT false,
    sepa_set_skonto_date_as_default_exec_date boolean DEFAULT false,
    sepa_set_skonto_date_buffer_in_days integer DEFAULT 0,
    delivery_date_interval integer DEFAULT 0,
    email_attachment_vc_files_checked boolean DEFAULT true,
    email_attachment_part_files_checked boolean DEFAULT true,
    email_attachment_record_files_checked boolean DEFAULT true,
    invoice_mail_settings public.invoice_mail_settings DEFAULT 'cp'::public.invoice_mail_settings,
    dunning_creator public.dunning_creator DEFAULT 'current_employee'::public.dunning_creator,
    address_street1 text,
    address_street2 text,
    address_zipcode text,
    address_city text,
    address_country text,
    workflow_po_ap_chart_id integer,
    carry_over_account_chart_id integer,
    profit_carried_forward_chart_id integer,
    loss_carried_forward_chart_id integer,
    contact_departments_use_textfield boolean,
    contact_titles_use_textfield boolean,
    create_zugferd_invoices integer,
    vc_greetings_use_textfield boolean,
    sales_serial_eq_charge boolean DEFAULT false NOT NULL,
    undo_transfer_interval integer DEFAULT 7,
    create_qrbill_invoices integer,
    customer_ustid_taxnummer_unique boolean DEFAULT false,
    vendor_ustid_taxnummer_unique boolean DEFAULT false,
    sales_delivery_order_check_stocked boolean DEFAULT false,
    purchase_delivery_order_check_stocked boolean DEFAULT false,
    ir_add_doc boolean DEFAULT false NOT NULL,
    ar_add_doc boolean DEFAULT false NOT NULL,
    ap_add_doc boolean DEFAULT false NOT NULL,
    gl_add_doc boolean DEFAULT false NOT NULL,
    reqdate_on boolean DEFAULT true,
    deliverydate_on boolean DEFAULT true,
    sales_delivery_order_check_service boolean DEFAULT true,
    purchase_delivery_order_check_service boolean DEFAULT true,
    produce_assembly_same_warehouse boolean DEFAULT true,
    produce_assembly_transfer_service boolean DEFAULT false,
    p_reclamation_record_number text DEFAULT 0 NOT NULL,
    s_reclamation_record_number text DEFAULT 0 NOT NULL,
    sales_reclamation_show_delete boolean DEFAULT true NOT NULL,
    purchase_reclamation_show_delete boolean DEFAULT true NOT NULL,
    reclamation_warn_no_reqdate boolean DEFAULT true NOT NULL,
    reclamation_warn_duplicate_parts boolean DEFAULT true NOT NULL,
    warn_no_delivery_order_for_invoice boolean DEFAULT false,
    order_warn_no_cusordnumber boolean DEFAULT false,
    partsgroup_required boolean DEFAULT false NOT NULL,
    print_interpolate_variables_in_positions boolean DEFAULT true NOT NULL,
    sales_purchase_record_numbers_changeable boolean DEFAULT false NOT NULL,
    always_record_links_from_order boolean DEFAULT false,
    sudonumber text,
    rdonumber text,
    advance_payment_clearing_chart_id integer,
    advance_payment_taxable_19_id integer,
    advance_payment_taxable_7_id integer,
    soinumber text,
    pqinumber text,
    lock_oe_subversions boolean DEFAULT false NOT NULL,
    qrbill_copy_invnumber boolean DEFAULT false,
    email_sender_sales_quotation text DEFAULT ''::text,
    email_sender_request_quotation text DEFAULT ''::text,
    email_sender_sales_order text DEFAULT ''::text,
    email_sender_purchase_order text DEFAULT ''::text,
    email_sender_invoice text DEFAULT ''::text,
    email_sender_purchase_invoice text DEFAULT ''::text,
    email_sender_letter text DEFAULT ''::text,
    email_sender_purchase_delivery_order text DEFAULT ''::text,
    email_sender_sales_delivery_order text DEFAULT ''::text,
    email_sender_dunning text DEFAULT ''::text,
    dunning_original_invoice_creation_mode public.invoice_creation_mode DEFAULT 'create_new'::public.invoice_creation_mode,
    record_links_from_order_with_myself boolean DEFAULT false,
    record_links_from_order_with_quotations boolean DEFAULT false,
    layout_style text,
    allowed_documents_with_no_positions text[],
    fuzzy_skonto boolean DEFAULT true,
    fuzzy_skonto_percentage real DEFAULT 0.5,
    transit_items_chart_id integer,
    webdav_sync_extern boolean DEFAULT false,
    webdav_sync_extern_url text,
    webdav_sync_extern_login text,
    webdav_sync_extern_pass text,
    yearend_method text DEFAULT 'default'::text NOT NULL,
    pocnumber text,
    sepa_export_xml boolean DEFAULT true,
    no_bank_proposals boolean DEFAULT false,
    check_bt_duplicates_endtoend boolean DEFAULT false,
    show_invoice_for_advance_payment boolean DEFAULT true NOT NULL,
    show_sales_order_intake boolean DEFAULT true NOT NULL,
    show_purchase_quotation_intake boolean DEFAULT true NOT NULL,
    show_purchase_order_confirmation boolean DEFAULT true NOT NULL,
    show_sales_reclamation boolean DEFAULT true NOT NULL,
    show_purchase_reclamation boolean DEFAULT true NOT NULL,
    produce_assembly_allow_empty_items boolean DEFAULT false,
    email_subject_transaction_description boolean DEFAULT false,
    order_item_input_position integer DEFAULT 0 NOT NULL,
    email_default_create_new_record_checked boolean DEFAULT true,
    gln text,
    email_attachment_project_files_checked boolean DEFAULT true,
    zugferd_ap_transaction_use_totals boolean DEFAULT false NOT NULL
);

--
--

CREATE SEQUENCE public.defaults_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.defaults_id_seq OWNED BY public.defaults.id;

--
--

CREATE SEQUENCE public.delivery_order_items_id
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.delivery_order_items (
    id integer DEFAULT nextval('public.delivery_order_items_id'::regclass) NOT NULL,
    delivery_order_id integer NOT NULL,
    parts_id integer NOT NULL,
    description text,
    qty numeric(25,5),
    sellprice numeric(15,5),
    discount real,
    project_id integer,
    reqdate date,
    serialnumber text,
    ordnumber text,
    transdate text,
    cusordnumber text,
    unit character varying(20),
    base_qty real,
    longdescription text,
    lastcost numeric(15,5),
    price_factor_id integer,
    price_factor numeric(15,5) DEFAULT 1,
    marge_price_factor numeric(15,5) DEFAULT 1,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    pricegroup_id integer,
    "position" integer NOT NULL,
    active_price_source text DEFAULT ''::text NOT NULL,
    active_discount_source text DEFAULT ''::text NOT NULL,
    orderer_id integer
);

--
--

CREATE TABLE public.delivery_order_items_stock (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    delivery_order_item_id integer NOT NULL,
    qty numeric(15,5) NOT NULL,
    unit character varying(20) NOT NULL,
    warehouse_id integer NOT NULL,
    bin_id integer NOT NULL,
    chargenumber text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    bestbefore date
);

--
--

CREATE TABLE public.delivery_orders (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    donumber text NOT NULL,
    ordnumber text,
    transdate date DEFAULT now(),
    vendor_id integer,
    customer_id integer,
    reqdate date,
    shippingpoint text,
    notes text,
    intnotes text,
    employee_id integer,
    closed boolean DEFAULT false,
    delivered boolean DEFAULT false,
    cusordnumber text,
    oreqnumber text,
    department_id integer,
    shipvia text,
    cp_id integer,
    language_id integer,
    shipto_id integer,
    globalproject_id integer,
    salesman_id integer,
    transaction_description text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    taxzone_id integer NOT NULL,
    taxincluded boolean,
    delivery_term_id integer,
    currency_id integer NOT NULL,
    payment_id integer,
    tax_point date,
    billing_address_id integer,
    record_type public.delivery_order_types NOT NULL,
    vendor_confirmation_number text
);

--
--

CREATE TABLE public.delivery_terms (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    description text,
    description_long text,
    sortkey integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    obsolete boolean DEFAULT false NOT NULL
);

--
--

CREATE TABLE public.department (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    description text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.drafts (
    id character varying(50) NOT NULL,
    module character varying(50) NOT NULL,
    submodule character varying(50) NOT NULL,
    description text,
    itime timestamp without time zone DEFAULT now(),
    form text,
    employee_id integer
);

--
--

CREATE TABLE public.dunning (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    trans_id integer,
    dunning_id integer,
    dunning_level integer,
    transdate date,
    duedate date,
    fee numeric(15,5),
    interest numeric(15,5),
    dunning_config_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    fee_interest_ar_id integer,
    original_invoice_printed boolean DEFAULT false
);

--
--

CREATE TABLE public.dunning_config (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    dunning_level integer,
    dunning_description text,
    active boolean,
    auto boolean,
    email boolean,
    terms integer,
    payment_terms integer,
    fee numeric(15,5),
    interest_rate numeric(15,5),
    email_body text,
    email_subject text,
    email_attachment boolean,
    template text,
    create_invoices_for_fees boolean DEFAULT true,
    print_original_invoice boolean
);

--
--

CREATE TABLE public.email_imports (
    id integer NOT NULL,
    host_name text NOT NULL,
    user_name text NOT NULL,
    folder text NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL
);

--
--

CREATE SEQUENCE public.email_imports_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.email_imports_id_seq OWNED BY public.email_imports.id;

--
--

CREATE TABLE public.email_journal (
    id integer NOT NULL,
    sender_id integer,
    "from" text NOT NULL,
    recipients text NOT NULL,
    sent_on timestamp without time zone DEFAULT now() NOT NULL,
    subject text NOT NULL,
    body text NOT NULL,
    headers text NOT NULL,
    extended_status text NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone DEFAULT now() NOT NULL,
    email_import_id integer,
    folder text,
    uid integer,
    obsolete boolean DEFAULT false NOT NULL,
    folder_uidvalidity text,
    status public.email_journal_status NOT NULL,
    record_type public.email_journal_record_type
);

--
--

CREATE TABLE public.email_journal_attachments (
    id integer NOT NULL,
    "position" integer NOT NULL,
    email_journal_id integer NOT NULL,
    name text NOT NULL,
    mime_type text NOT NULL,
    content bytea NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone DEFAULT now() NOT NULL,
    file_id integer DEFAULT 0 NOT NULL
);

--
--

CREATE SEQUENCE public.email_journal_attachments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.email_journal_attachments_id_seq OWNED BY public.email_journal_attachments.id;

--
--

CREATE SEQUENCE public.email_journal_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.email_journal_id_seq OWNED BY public.email_journal.id;

--
--

CREATE TABLE public.employee (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    login text,
    startdate date DEFAULT ('now'::text)::date,
    enddate date,
    sales boolean DEFAULT true,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    name text,
    deleted boolean DEFAULT false,
    deleted_email text,
    deleted_signature text,
    deleted_tel text,
    deleted_fax text
);

--
--

CREATE TABLE public.employee_project_invoices (
    employee_id integer NOT NULL,
    project_id integer NOT NULL
);

--
--

CREATE VIEW public.eur_categories AS
 SELECT "*VALUES*".column1 AS id,
    "*VALUES*".column2 AS description
   FROM (VALUES (1,'Umsatzerlöse'::text), (2,'sonstige Erlöse'::text), (3,'Privatanteile'::text), (4,'Zinserträge'::text), (5,'Ausserordentliche Erträge'::text), (6,'Vereinnahmte Umsatzst.'::text), (7,'Umsatzsteuererstattungen'::text), (8,'Wareneingänge'::text), (9,'Löhne und Gehälter'::text), (10,'Gesetzl. sozialer Aufw.'::text), (11,'Mieten'::text), (12,'Gas, Strom, Wasser'::text), (13,'Instandhaltung'::text), (14,'Steuern, Versich., Beiträge'::text), (15,'Kfz-Steuern'::text), (16,'Kfz-Versicherungen'::text), (17,'Sonst. Fahrzeugkosten'::text), (18,'Werbe- und Reisekosten'::text), (19,'Instandhaltung u. Werkzeuge'::text), (20,'Fachzeitschriften, Bücher'::text), (21,'Miete für Einrichtungen'::text), (22,'Rechts- und Beratungskosten'::text), (23,'Bürobedarf, Porto, Telefon'::text), (24,'Sonstige Aufwendungen'::text), (25,'Abschreibungen auf Anlagever.'::text), (26,'Abschreibungen auf GWG'::text), (27,'Vorsteuer'::text), (28,'Umsatzsteuerzahlungen'::text), (29,'Zinsaufwand'::text), (30,'Ausserordentlicher Aufwand'::text), (31,'Betriebliche Steuern'::text)) "*VALUES*";

--
--

CREATE TABLE public.exchangerate (
    transdate date,
    buy numeric(15,5),
    sell numeric(15,5),
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    id integer NOT NULL,
    currency_id integer NOT NULL
);

--
--

CREATE SEQUENCE public.exchangerate_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.exchangerate_id_seq OWNED BY public.exchangerate.id;

--
--

CREATE TABLE public.file_full_texts (
    id integer NOT NULL,
    file_id integer NOT NULL,
    full_text text NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.file_full_texts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.file_full_texts_id_seq OWNED BY public.file_full_texts.id;

--
--

CREATE TABLE public.file_versions (
    guid text NOT NULL,
    file_id integer NOT NULL,
    version integer NOT NULL,
    file_location text NOT NULL,
    doc_path text NOT NULL,
    backend text NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.files (
    id integer NOT NULL,
    object_id integer NOT NULL,
    file_name text NOT NULL,
    file_type text NOT NULL,
    mime_type text NOT NULL,
    source text NOT NULL,
    backend_data text,
    title character varying(45),
    description text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    object_type public.file_object_types NOT NULL,
    print_variant text,
    backend public.files_backends NOT NULL,
    uid text
);

--
--

CREATE SEQUENCE public.files_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.files_id_seq OWNED BY public.files.id;

--
--

CREATE TABLE public.finanzamt (
    fa_land_nr text,
    fa_bufa_nr text,
    fa_name text,
    fa_strasse text,
    fa_plz text,
    fa_ort text,
    fa_telefon text,
    fa_fax text,
    fa_plz_grosskunden text,
    fa_plz_postfach text,
    fa_postfach text,
    fa_blz_1 text,
    fa_kontonummer_1 text,
    fa_bankbezeichnung_1 text,
    fa_blz_2 text,
    fa_kontonummer_2 text,
    fa_bankbezeichnung_2 text,
    fa_oeffnungszeiten text,
    fa_email text,
    fa_internet text,
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.finanzamt_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.finanzamt_id_seq OWNED BY public.finanzamt.id;

--
--

CREATE TABLE public.follow_up_access (
    who integer NOT NULL,
    what integer NOT NULL,
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.follow_up_access_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.follow_up_access_id_seq OWNED BY public.follow_up_access.id;

--
--

CREATE TABLE public.follow_up_created_for_employees (
    id integer NOT NULL,
    follow_up_id integer NOT NULL,
    employee_id integer NOT NULL
);

--
--

CREATE SEQUENCE public.follow_up_created_for_employees_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.follow_up_created_for_employees_id_seq OWNED BY public.follow_up_created_for_employees.id;

--
--

CREATE TABLE public.follow_up_done (
    id integer NOT NULL,
    follow_up_id integer NOT NULL,
    done_at timestamp without time zone DEFAULT now() NOT NULL,
    employee_id integer
);

--
--

CREATE SEQUENCE public.follow_up_done_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.follow_up_done_id_seq OWNED BY public.follow_up_done.id;

--
--

CREATE SEQUENCE public.follow_up_id
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE SEQUENCE public.follow_up_link_id
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.follow_up_links (
    id integer DEFAULT nextval('public.follow_up_link_id'::regclass) NOT NULL,
    follow_up_id integer NOT NULL,
    trans_id integer NOT NULL,
    trans_type text NOT NULL,
    trans_info text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.follow_ups (
    id integer DEFAULT nextval('public.follow_up_id'::regclass) NOT NULL,
    follow_up_date date NOT NULL,
    note_id integer NOT NULL,
    created_by integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.generic_translations (
    id integer NOT NULL,
    language_id integer,
    translation_type character varying(100) NOT NULL,
    translation_id integer,
    translation text
);

--
--

CREATE SEQUENCE public.generic_translations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.generic_translations_id_seq OWNED BY public.generic_translations.id;

--
--

CREATE TABLE public.gl (
    id integer DEFAULT nextval(('glid'::text)::regclass) NOT NULL,
    reference text,
    description text,
    transdate date DEFAULT ('now'::text)::date,
    gldate date DEFAULT ('now'::text)::date,
    employee_id integer,
    notes text,
    department_id integer,
    taxincluded boolean,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    type text,
    ob_transaction boolean,
    cb_transaction boolean,
    storno boolean DEFAULT false,
    storno_id integer,
    deliverydate date,
    imported boolean DEFAULT false,
    tax_point date,
    transaction_description text
);

--
--

CREATE SEQUENCE public.glid
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1;

--
--

CREATE TABLE public.greetings (
    id integer NOT NULL,
    description text NOT NULL
);

--
--

CREATE SEQUENCE public.greetings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.greetings_id_seq OWNED BY public.greetings.id;

--
--

CREATE TABLE public.history_erp (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    trans_id integer,
    employee_id integer,
    addition text,
    what_done text,
    itime timestamp without time zone DEFAULT now(),
    snumbers text
);

--
--

CREATE TABLE public.inventory (
    warehouse_id integer NOT NULL,
    parts_id integer NOT NULL,
    oe_id integer,
    delivery_order_items_stock_id integer,
    shippingdate date NOT NULL,
    employee_id integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    bin_id integer NOT NULL,
    qty numeric(25,5),
    trans_id integer NOT NULL,
    trans_type_id integer NOT NULL,
    project_id integer,
    chargenumber text DEFAULT ''::text NOT NULL,
    comment text,
    bestbefore date,
    id integer NOT NULL,
    invoice_id integer
);

--
--

CREATE SEQUENCE public.inventory_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.inventory_id_seq OWNED BY public.inventory.id;

--
--

CREATE TABLE public.invoice (
    id integer DEFAULT nextval(('invoiceid'::text)::regclass) NOT NULL,
    trans_id integer,
    parts_id integer,
    description text,
    qty numeric(25,5),
    allocated real,
    sellprice numeric(15,5),
    fxsellprice numeric(15,5),
    discount real,
    assemblyitem boolean DEFAULT false,
    project_id integer,
    deliverydate date,
    serialnumber text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    pricegroup_id integer,
    ordnumber text,
    transdate text,
    cusordnumber text,
    unit character varying(20),
    base_qty real,
    subtotal boolean DEFAULT false,
    longdescription text,
    marge_total numeric(15,5),
    marge_percent numeric(15,5),
    lastcost numeric(15,5),
    price_factor_id integer,
    price_factor numeric(15,5) DEFAULT 1,
    marge_price_factor numeric(15,5) DEFAULT 1,
    donumber text,
    "position" integer NOT NULL,
    active_price_source text DEFAULT ''::text NOT NULL,
    active_discount_source text DEFAULT ''::text NOT NULL,
    inventory_chart_id integer,
    expense_chart_id integer,
    tax_id integer,
    tax_chart_type character varying(20)
);

--
--

CREATE SEQUENCE public.invoiceid
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1;

--
--

CREATE TABLE public.language (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    description text,
    template_code text,
    article_code text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    output_numberformat text,
    output_dateformat text,
    output_longdates boolean,
    obsolete boolean DEFAULT false
);

--
--

CREATE TABLE public.leads (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    lead character varying(50)
);

--
--

CREATE TABLE public.letter (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    customer_id integer,
    letternumber text,
    subject text,
    greeting text,
    body text,
    employee_id integer,
    salesman_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    date date,
    reference text,
    intnotes text,
    cp_id integer,
    vendor_id integer
);

--
--

CREATE TABLE public.letter_draft (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    customer_id integer,
    cp_id integer,
    letternumber text,
    date date,
    intnotes text,
    reference text,
    subject text,
    greeting text,
    body text,
    employee_id integer,
    salesman_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    vendor_id integer
);

--
--

CREATE SEQUENCE public.makemodel_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.makemodel (
    parts_id integer,
    model text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    lastcost numeric(15,5),
    lastupdate date,
    sortorder integer,
    make integer,
    id integer DEFAULT nextval('public.makemodel_id_seq'::regclass) NOT NULL,
    part_description text,
    part_longdescription text
);

--
--

CREATE SEQUENCE public.note_id
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.notes (
    id integer DEFAULT nextval('public.note_id'::regclass) NOT NULL,
    subject text,
    body text,
    created_by integer NOT NULL,
    trans_id integer,
    trans_module character varying(10),
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.oe (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    ordnumber text NOT NULL,
    transdate date DEFAULT ('now'::text)::date,
    vendor_id integer,
    customer_id integer,
    amount numeric(15,5),
    netamount numeric(15,5),
    reqdate date,
    taxincluded boolean,
    shippingpoint text,
    notes text,
    employee_id integer,
    closed boolean DEFAULT false,
    quonumber text,
    cusordnumber text,
    intnotes text,
    department_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    shipvia text,
    cp_id integer,
    language_id integer,
    payment_id integer,
    delivery_customer_id integer,
    delivery_vendor_id integer,
    taxzone_id integer NOT NULL,
    proforma boolean DEFAULT false,
    shipto_id integer,
    order_probability integer DEFAULT 0 NOT NULL,
    expected_billing_date date,
    globalproject_id integer,
    delivered boolean DEFAULT false,
    salesman_id integer,
    marge_total numeric(15,5),
    marge_percent numeric(15,5),
    transaction_description text,
    delivery_term_id integer,
    currency_id integer NOT NULL,
    exchangerate numeric(15,5),
    tax_point date,
    billing_address_id integer,
    order_status_id integer,
    record_type public.order_types NOT NULL,
    vendor_confirmation_number text
);

--
--

CREATE TABLE public.oe_version (
    oe_id integer NOT NULL,
    version integer NOT NULL,
    final_version boolean DEFAULT false,
    email_journal_id integer,
    file_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.order_statuses (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    "position" integer NOT NULL,
    obsolete boolean DEFAULT false NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.order_statuses_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.order_statuses_id_seq OWNED BY public.order_statuses.id;

--
--

CREATE TABLE public.orderitems (
    trans_id integer,
    parts_id integer,
    description text,
    qty numeric(25,5),
    sellprice numeric(15,5),
    discount real,
    project_id integer,
    reqdate date,
    ship real,
    serialnumber text,
    id integer DEFAULT nextval(('orderitemsid'::text)::regclass) NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    pricegroup_id integer,
    ordnumber text,
    transdate text,
    cusordnumber text,
    unit character varying(20),
    base_qty real,
    subtotal boolean DEFAULT false,
    longdescription text,
    marge_total numeric(15,5),
    marge_percent numeric(15,5),
    lastcost numeric(15,5),
    price_factor_id integer,
    price_factor numeric(15,5) DEFAULT 1,
    marge_price_factor numeric(15,5) DEFAULT 1,
    "position" integer NOT NULL,
    active_price_source text DEFAULT ''::text NOT NULL,
    active_discount_source text DEFAULT ''::text NOT NULL,
    optional boolean DEFAULT false,
    recurring_billing_mode public.items_recurring_billing_mode DEFAULT 'always'::public.items_recurring_billing_mode NOT NULL,
    recurring_billing_invoice_id integer,
    orderer_id integer
);

--
--

CREATE SEQUENCE public.orderitemsid
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    MAXVALUE 2147483647
    CACHE 1
    CYCLE;

--
--

CREATE TABLE public.part_classifications (
    id integer NOT NULL,
    description text,
    abbreviation text,
    used_for_purchase boolean DEFAULT true NOT NULL,
    used_for_sale boolean DEFAULT true NOT NULL,
    report_separate boolean DEFAULT false NOT NULL
);

--
--

CREATE SEQUENCE public.part_classifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.part_classifications_id_seq OWNED BY public.part_classifications.id;

--
--

CREATE TABLE public.part_customer_prices (
    id integer NOT NULL,
    parts_id integer NOT NULL,
    customer_id integer NOT NULL,
    customer_partnumber text DEFAULT ''::text,
    price numeric(15,5) DEFAULT 0,
    sortorder integer DEFAULT 0,
    lastupdate date DEFAULT now(),
    part_description text,
    part_longdescription text
);

--
--

CREATE SEQUENCE public.part_customer_prices_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.part_customer_prices_id_seq OWNED BY public.part_customer_prices.id;

--
--

CREATE TABLE public.parts (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    partnumber text NOT NULL,
    description text,
    listprice numeric(15,5),
    sellprice numeric(15,5),
    lastcost numeric(15,5),
    priceupdate date DEFAULT ('now'::text)::date,
    weight real,
    notes text,
    makemodel boolean DEFAULT false,
    rop real,
    shop boolean DEFAULT false,
    obsolete boolean DEFAULT false NOT NULL,
    bom boolean DEFAULT false,
    image text,
    drawing text,
    microfiche text,
    partsgroup_id integer,
    ve integer,
    gv numeric(15,5),
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    unit character varying(20) NOT NULL,
    formel text,
    not_discountable boolean DEFAULT false,
    buchungsgruppen_id integer,
    payment_id integer,
    ean text,
    price_factor_id integer,
    onhand numeric(25,5) DEFAULT 0,
    stockable boolean DEFAULT false,
    has_sernumber boolean DEFAULT false,
    warehouse_id integer,
    bin_id integer,
    classification_id integer DEFAULT 0,
    part_type public.part_type_enum NOT NULL,
    order_qty numeric(15,5) DEFAULT 0 NOT NULL,
    order_locked boolean DEFAULT false,
    tariff_code text
);

--
--

CREATE TABLE public.parts_price_history (
    id integer NOT NULL,
    part_id integer NOT NULL,
    valid_from timestamp without time zone NOT NULL,
    lastcost numeric(15,5),
    listprice numeric(15,5),
    sellprice numeric(15,5),
    price_factor numeric(15,5) DEFAULT 1
);

--
--

CREATE SEQUENCE public.parts_price_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.parts_price_history_id_seq OWNED BY public.parts_price_history.id;

--
--

CREATE TABLE public.partsgroup (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    partsgroup text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    obsolete boolean DEFAULT false,
    sortkey integer NOT NULL
);

--
--

CREATE TABLE public.payment_terms (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    description text,
    description_long text,
    terms_netto integer,
    terms_skonto integer,
    percent_skonto real,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    sortkey integer NOT NULL,
    auto_calculation boolean NOT NULL,
    description_long_invoice text,
    obsolete boolean DEFAULT false
);

--
--

CREATE TABLE public.periodic_invoices (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    config_id integer NOT NULL,
    ar_id integer NOT NULL,
    period_start_date date NOT NULL,
    itime timestamp without time zone DEFAULT now()
);

--
--

CREATE TABLE public.periodic_invoices_configs (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    oe_id integer NOT NULL,
    periodicity character varying(1) NOT NULL,
    print boolean DEFAULT false,
    printer_id integer,
    copies integer,
    active boolean DEFAULT true,
    terminated boolean DEFAULT false,
    start_date date,
    end_date date,
    ar_chart_id integer NOT NULL,
    extend_automatically_by integer,
    first_billing_date date,
    order_value_periodicity character varying(1) NOT NULL,
    direct_debit boolean DEFAULT false NOT NULL,
    send_email boolean DEFAULT false NOT NULL,
    email_recipient_contact_id integer,
    email_recipient_address text,
    email_sender text,
    email_subject text,
    email_body text,
    CONSTRAINT periodic_invoices_configs_valid_order_value_periodicity CHECK (((order_value_periodicity)::text = ANY ((ARRAY['p'::character varying, 'm'::character varying, 'q'::character varying, 'b'::character varying, 'y'::character varying, '2'::character varying, '3'::character varying, '4'::character varying, '5'::character varying])::text[]))),
    CONSTRAINT periodic_invoices_configs_valid_periodicity CHECK (((periodicity)::text = ANY ((ARRAY['o'::character varying, 'm'::character varying, 'q'::character varying, 'b'::character varying, 'y'::character varying])::text[])))
);

--
--

CREATE TABLE public.price_factors (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    description text,
    factor numeric(15,5),
    sortkey integer
);

--
--

CREATE TABLE public.price_rule_items (
    id integer NOT NULL,
    price_rules_id integer NOT NULL,
    type text,
    op text,
    custom_variable_configs_id integer,
    value_text text,
    value_int integer,
    value_date date,
    value_num numeric(15,5),
    itime timestamp without time zone,
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.price_rule_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.price_rule_items_id_seq OWNED BY public.price_rule_items.id;

--
--

CREATE TABLE public.price_rules (
    id integer NOT NULL,
    name text,
    type text,
    priority integer DEFAULT 3 NOT NULL,
    price numeric(15,5),
    reduction numeric(15,5),
    obsolete boolean DEFAULT false NOT NULL,
    itime timestamp without time zone,
    mtime timestamp without time zone,
    discount numeric(15,5)
);

--
--

CREATE SEQUENCE public.price_rules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.price_rules_id_seq OWNED BY public.price_rules.id;

--
--

CREATE TABLE public.pricegroup (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    pricegroup text NOT NULL,
    obsolete boolean DEFAULT false,
    sortkey integer NOT NULL
);

--
--

CREATE TABLE public.prices (
    parts_id integer NOT NULL,
    pricegroup_id integer NOT NULL,
    price numeric(15,5),
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.prices_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.prices_id_seq OWNED BY public.prices.id;

--
--

CREATE TABLE public.printers (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    printer_description text NOT NULL,
    printer_command text,
    template_code text
);

--
--

CREATE TABLE public.project (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    projectnumber text,
    description text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    active boolean DEFAULT true,
    customer_id integer,
    valid boolean DEFAULT true,
    project_type_id integer NOT NULL,
    start_date date,
    end_date date,
    billable_customer_id integer,
    budget_cost numeric(15,5) DEFAULT 0 NOT NULL,
    order_value numeric(15,5) DEFAULT 0 NOT NULL,
    budget_minutes integer DEFAULT 0 NOT NULL,
    timeframe boolean DEFAULT false NOT NULL,
    project_status_id integer NOT NULL
);

--
--

CREATE TABLE public.project_participants (
    id integer NOT NULL,
    project_id integer NOT NULL,
    employee_id integer NOT NULL,
    project_role_id integer NOT NULL,
    minutes integer DEFAULT 0 NOT NULL,
    cost_per_hour numeric(15,5),
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.project_participants_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.project_participants_id_seq OWNED BY public.project_participants.id;

--
--

CREATE TABLE public.project_phase_participants (
    id integer NOT NULL,
    project_phase_id integer NOT NULL,
    employee_id integer NOT NULL,
    project_role_id integer NOT NULL,
    minutes integer DEFAULT 0 NOT NULL,
    cost_per_hour numeric(15,5),
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.project_phase_participants_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.project_phase_participants_id_seq OWNED BY public.project_phase_participants.id;

--
--

CREATE TABLE public.project_phases (
    id integer NOT NULL,
    project_id integer,
    start_date date,
    end_date date,
    name text NOT NULL,
    description text NOT NULL,
    budget_minutes integer DEFAULT 0 NOT NULL,
    budget_cost numeric(15,5) DEFAULT 0 NOT NULL,
    general_minutes integer DEFAULT 0 NOT NULL,
    general_cost_per_hour numeric(15,5) DEFAULT 0 NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.project_phases_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.project_phases_id_seq OWNED BY public.project_phases.id;

--
--

CREATE TABLE public.project_roles (
    id integer NOT NULL,
    name text NOT NULL,
    description text NOT NULL,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.project_roles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.project_roles_id_seq OWNED BY public.project_roles.id;

--
--

CREATE TABLE public.project_statuses (
    id integer NOT NULL,
    name text NOT NULL,
    description text NOT NULL,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.project_status_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.project_status_id_seq OWNED BY public.project_statuses.id;

--
--

CREATE TABLE public.project_types (
    id integer NOT NULL,
    "position" integer NOT NULL,
    description text,
    internal boolean DEFAULT false NOT NULL
);

--
--

CREATE SEQUENCE public.project_types_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.project_types_id_seq OWNED BY public.project_types.id;

--
--

CREATE TABLE public.purchase_basket_items (
    id integer NOT NULL,
    part_id integer,
    orderer_id integer,
    qty numeric(15,5) NOT NULL,
    cleared boolean DEFAULT false NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.purchase_basket_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.purchase_basket_items_id_seq OWNED BY public.purchase_basket_items.id;

--
--

CREATE TABLE public.reclamation_items (
    id integer NOT NULL,
    reclamation_id integer NOT NULL,
    reason_id integer NOT NULL,
    reason_description_ext text,
    reason_description_int text,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    project_id integer,
    parts_id integer NOT NULL,
    description text,
    longdescription text,
    serialnumber text,
    base_qty real,
    qty real,
    unit character varying(20),
    sellprice numeric(15,5),
    lastcost numeric(15,5),
    discount real,
    pricegroup_id integer,
    price_factor_id integer,
    price_factor numeric(15,5) DEFAULT 1,
    active_price_source text DEFAULT ''::text NOT NULL,
    active_discount_source text DEFAULT ''::text NOT NULL,
    reqdate date,
    CONSTRAINT reclamation_items_position_check CHECK (("position" > 0))
);

--
--

CREATE SEQUENCE public.reclamation_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.reclamation_items_id_seq OWNED BY public.reclamation_items.id;

--
--

CREATE TABLE public.reclamation_reasons (
    id integer NOT NULL,
    name text NOT NULL,
    description text NOT NULL,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    valid_for_sales boolean DEFAULT false NOT NULL,
    valid_for_purchase boolean DEFAULT false NOT NULL
);

--
--

CREATE SEQUENCE public.reclamation_reasons_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.reclamation_reasons_id_seq OWNED BY public.reclamation_reasons.id;

--
--

CREATE TABLE public.reclamations (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    record_number text NOT NULL,
    transdate date DEFAULT now(),
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    delivered boolean DEFAULT false NOT NULL,
    closed boolean DEFAULT false NOT NULL,
    employee_id integer NOT NULL,
    globalproject_id integer,
    delivery_term_id integer,
    shipto_id integer,
    department_id integer,
    contact_id integer,
    shipvia text,
    transaction_description text,
    shippingpoint text,
    cv_record_number text,
    reqdate date,
    amount numeric(15,5),
    netamount numeric(15,5),
    payment_id integer,
    currency_id integer NOT NULL,
    taxincluded boolean NOT NULL,
    tax_point date,
    exchangerate numeric(15,5),
    taxzone_id integer NOT NULL,
    notes text,
    intnotes text,
    language_id integer,
    salesman_id integer,
    customer_id integer,
    vendor_id integer,
    billing_address_id integer,
    record_type public.reclamation_types NOT NULL,
    CONSTRAINT reclamations_customervendor_check CHECK ((((customer_id IS NOT NULL) AND (vendor_id IS NULL)) OR ((vendor_id IS NOT NULL) AND (customer_id IS NULL))))
);

--
--

CREATE TABLE public.reconciliation_links (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    bank_transaction_id integer NOT NULL,
    acc_trans_id bigint NOT NULL,
    rec_group integer NOT NULL
);

--
--

CREATE TABLE public.record_links (
    from_table character varying(50) NOT NULL,
    from_id integer NOT NULL,
    to_table character varying(50) NOT NULL,
    to_id integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.record_links_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.record_links_id_seq OWNED BY public.record_links.id;

--
--

CREATE TABLE public.record_template_items (
    id integer NOT NULL,
    record_template_id integer NOT NULL,
    chart_id integer NOT NULL,
    tax_id integer NOT NULL,
    project_id integer,
    amount1 numeric(15,5) NOT NULL,
    amount2 numeric(15,5),
    source text,
    memo text
);

--
--

CREATE SEQUENCE public.record_template_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.record_template_items_id_seq OWNED BY public.record_template_items.id;

--
--

CREATE TABLE public.record_templates (
    id integer NOT NULL,
    template_name text NOT NULL,
    template_type public.record_template_type NOT NULL,
    customer_id integer,
    vendor_id integer,
    currency_id integer NOT NULL,
    department_id integer,
    project_id integer,
    employee_id integer,
    taxincluded boolean DEFAULT false NOT NULL,
    direct_debit boolean DEFAULT false NOT NULL,
    ob_transaction boolean DEFAULT false NOT NULL,
    cb_transaction boolean DEFAULT false NOT NULL,
    reference text,
    description text,
    ordnumber text,
    notes text,
    ar_ap_chart_id integer,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone DEFAULT now() NOT NULL,
    show_details boolean DEFAULT false NOT NULL,
    payment_id integer,
    transaction_description text
);

--
--

CREATE SEQUENCE public.record_templates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.record_templates_id_seq OWNED BY public.record_templates.id;

--
--

CREATE TABLE public.requirement_spec_acceptance_statuses (
    id integer NOT NULL,
    name text NOT NULL,
    description text,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.requirement_spec_acceptance_statuses_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_acceptance_statuses_id_seq OWNED BY public.requirement_spec_acceptance_statuses.id;

--
--

CREATE TABLE public.requirement_spec_complexities (
    id integer NOT NULL,
    description text NOT NULL,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.requirement_spec_complexities_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_complexities_id_seq OWNED BY public.requirement_spec_complexities.id;

--
--

CREATE TABLE public.requirement_spec_item_dependencies (
    depending_item_id integer NOT NULL,
    depended_item_id integer NOT NULL
);

--
--

CREATE TABLE public.requirement_spec_items (
    id integer NOT NULL,
    requirement_spec_id integer NOT NULL,
    item_type text NOT NULL,
    parent_id integer,
    "position" integer NOT NULL,
    fb_number text NOT NULL,
    title text,
    description text,
    complexity_id integer,
    risk_id integer,
    time_estimation numeric(12,2) DEFAULT 0 NOT NULL,
    is_flagged boolean DEFAULT false NOT NULL,
    acceptance_status_id integer,
    acceptance_text text,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone,
    sellprice_factor numeric(10,5) DEFAULT 1,
    order_part_id integer,
    CONSTRAINT valid_item_type CHECK (((item_type = 'section'::text) OR (item_type = 'function-block'::text) OR (item_type = 'sub-function-block'::text))),
    CONSTRAINT valid_parent_id_for_item_type CHECK (
CASE
    WHEN (item_type = 'section'::text) THEN (parent_id IS NULL)
    ELSE (parent_id IS NOT NULL)
END)
);

--
--

CREATE SEQUENCE public.requirement_spec_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_items_id_seq OWNED BY public.requirement_spec_items.id;

--
--

CREATE TABLE public.requirement_spec_orders (
    id integer NOT NULL,
    requirement_spec_id integer NOT NULL,
    order_id integer NOT NULL,
    version_id integer,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone DEFAULT now() NOT NULL
);

--
--

CREATE SEQUENCE public.requirement_spec_orders_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_orders_id_seq OWNED BY public.requirement_spec_orders.id;

--
--

CREATE TABLE public.requirement_spec_parts (
    id integer NOT NULL,
    requirement_spec_id integer NOT NULL,
    part_id integer NOT NULL,
    unit_id integer NOT NULL,
    qty numeric(15,5) NOT NULL,
    description text NOT NULL,
    "position" integer NOT NULL
);

--
--

CREATE SEQUENCE public.requirement_spec_parts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_parts_id_seq OWNED BY public.requirement_spec_parts.id;

--
--

CREATE TABLE public.requirement_spec_pictures (
    id integer NOT NULL,
    requirement_spec_id integer NOT NULL,
    text_block_id integer NOT NULL,
    "position" integer NOT NULL,
    number text NOT NULL,
    description text,
    picture_file_name text NOT NULL,
    picture_content_type text NOT NULL,
    picture_mtime timestamp without time zone DEFAULT now() NOT NULL,
    picture_content bytea NOT NULL,
    picture_width integer NOT NULL,
    picture_height integer NOT NULL,
    thumbnail_content_type text NOT NULL,
    thumbnail_content bytea NOT NULL,
    thumbnail_width integer NOT NULL,
    thumbnail_height integer NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.requirement_spec_pictures_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_pictures_id_seq OWNED BY public.requirement_spec_pictures.id;

--
--

CREATE TABLE public.requirement_spec_predefined_texts (
    id integer NOT NULL,
    description text NOT NULL,
    title text NOT NULL,
    text text NOT NULL,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    useable_for_text_blocks boolean DEFAULT false NOT NULL,
    useable_for_sections boolean DEFAULT false NOT NULL
);

--
--

CREATE SEQUENCE public.requirement_spec_predefined_texts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_predefined_texts_id_seq OWNED BY public.requirement_spec_predefined_texts.id;

--
--

CREATE TABLE public.requirement_spec_risks (
    id integer NOT NULL,
    description text NOT NULL,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.requirement_spec_risks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_risks_id_seq OWNED BY public.requirement_spec_risks.id;

--
--

CREATE TABLE public.requirement_spec_statuses (
    id integer NOT NULL,
    name text NOT NULL,
    description text NOT NULL,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.requirement_spec_statuses_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_statuses_id_seq OWNED BY public.requirement_spec_statuses.id;

--
--

CREATE TABLE public.requirement_spec_text_blocks (
    id integer NOT NULL,
    requirement_spec_id integer NOT NULL,
    title text NOT NULL,
    text text,
    "position" integer NOT NULL,
    output_position integer DEFAULT 1 NOT NULL,
    is_flagged boolean DEFAULT false NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.requirement_spec_text_blocks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_text_blocks_id_seq OWNED BY public.requirement_spec_text_blocks.id;

--
--

CREATE TABLE public.requirement_spec_types (
    id integer NOT NULL,
    description text NOT NULL,
    "position" integer NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    section_number_format text DEFAULT 'A00'::text NOT NULL,
    function_block_number_format text DEFAULT 'FB000'::text NOT NULL,
    template_file_name text
);

--
--

CREATE SEQUENCE public.requirement_spec_types_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_types_id_seq OWNED BY public.requirement_spec_types.id;

--
--

CREATE TABLE public.requirement_spec_versions (
    id integer NOT NULL,
    version_number integer,
    description text NOT NULL,
    comment text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    requirement_spec_id integer NOT NULL,
    working_copy_id integer
);

--
--

CREATE SEQUENCE public.requirement_spec_versions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_spec_versions_id_seq OWNED BY public.requirement_spec_versions.id;

--
--

CREATE TABLE public.requirement_specs (
    id integer NOT NULL,
    type_id integer NOT NULL,
    status_id integer,
    customer_id integer,
    project_id integer,
    title text NOT NULL,
    hourly_rate numeric(8,2) DEFAULT 0 NOT NULL,
    working_copy_id integer,
    previous_section_number integer NOT NULL,
    previous_fb_number integer NOT NULL,
    is_template boolean DEFAULT false,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    time_estimation numeric(12,2) DEFAULT 0 NOT NULL,
    previous_picture_number integer DEFAULT 0 NOT NULL,
    CONSTRAINT requirement_specs_is_template_or_has_customer_status_type CHECK ((is_template OR ((type_id IS NOT NULL) AND (status_id IS NOT NULL) AND (customer_id IS NOT NULL))))
);

--
--

CREATE SEQUENCE public.requirement_specs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.requirement_specs_id_seq OWNED BY public.requirement_specs.id;

--
--

CREATE TABLE public.schema_info (
    tag text NOT NULL,
    login text,
    itime timestamp without time zone DEFAULT now()
);

--
--

CREATE TABLE public.secrets (
    id integer NOT NULL,
    tag text NOT NULL,
    description text,
    cipher bytea,
    iv bytea,
    salt text,
    utf_flag boolean NOT NULL
);

--
--

CREATE SEQUENCE public.secrets_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.secrets_id_seq OWNED BY public.secrets.id;

--
--

CREATE SEQUENCE public.sepa_export_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

CREATE TABLE public.sepa_export (
    id integer DEFAULT nextval('public.sepa_export_id_seq'::regclass) NOT NULL,
    employee_id integer NOT NULL,
    executed boolean DEFAULT false,
    closed boolean DEFAULT false,
    itime timestamp without time zone DEFAULT now(),
    vc character varying(10)
);

--
--

CREATE TABLE public.sepa_export_items (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    sepa_export_id integer NOT NULL,
    ap_id integer,
    chart_id integer NOT NULL,
    amount numeric(25,5),
    reference character varying(140),
    requested_execution_date date,
    executed boolean DEFAULT false,
    execution_date date,
    our_iban character varying(100),
    our_bic character varying(100),
    vc_iban character varying(100),
    vc_bic character varying(100),
    end_to_end_id character varying(35),
    our_depositor text,
    vc_depositor text,
    ar_id integer,
    vc_mandator_id text,
    vc_mandate_date_of_signature date,
    payment_type text DEFAULT 'without_skonto'::text,
    skonto_amount numeric(25,5)
);

--
--

CREATE TABLE public.sepa_export_message_ids (
    id integer NOT NULL,
    sepa_export_id integer NOT NULL,
    message_id text NOT NULL
);

--
--

CREATE SEQUENCE public.sepa_export_message_ids_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.sepa_export_message_ids_id_seq OWNED BY public.sepa_export_message_ids.id;

--
--

CREATE TABLE public.shipto (
    trans_id integer,
    shiptoname text,
    shiptodepartment_1 text,
    shiptodepartment_2 text,
    shiptostreet text,
    shiptozipcode text,
    shiptocity text,
    shiptocountry text,
    shiptocontact text,
    shiptophone text,
    shiptofax text,
    shiptoemail text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    module text,
    shipto_id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    shiptocp_gender text,
    shiptogln text
);

--
--

CREATE TABLE public.shop_images (
    id integer NOT NULL,
    file_id integer,
    "position" integer,
    thumbnail_content bytea,
    org_file_width integer,
    org_file_height integer,
    thumbnail_content_type text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    object_id text NOT NULL
);

--
--

CREATE SEQUENCE public.shop_images_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.shop_images_id_seq OWNED BY public.shop_images.id;

--
--

CREATE TABLE public.shop_order_items (
    id integer NOT NULL,
    shop_trans_id text NOT NULL,
    shop_order_id integer,
    description text,
    partnumber text,
    "position" integer,
    tax_rate numeric(15,2),
    quantity numeric(25,5),
    price numeric(15,5),
    active_price_source text,
    discount real,
    discount_code text,
    identifier text
);

--
--

CREATE SEQUENCE public.shop_order_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.shop_order_items_id_seq OWNED BY public.shop_order_items.id;

--
--

CREATE TABLE public.shop_orders (
    id integer NOT NULL,
    shop_trans_id text NOT NULL,
    shop_ordernumber text,
    shop_customer_comment text,
    amount numeric(15,5),
    netamount numeric(15,5),
    order_date timestamp without time zone,
    shipping_costs numeric(15,5),
    shipping_costs_net numeric(15,5),
    shipping_costs_id integer,
    tax_included boolean,
    payment_id integer,
    payment_description text,
    shop_id integer,
    host text,
    remote_ip text,
    transferred boolean DEFAULT false,
    transfer_date date,
    kivi_customer_id integer,
    shop_customer_id integer,
    shop_customer_number text,
    customer_lastname text,
    customer_firstname text,
    customer_company text,
    customer_street text,
    customer_zipcode text,
    customer_city text,
    customer_country text,
    customer_greeting text,
    customer_department text,
    customer_vat text,
    customer_phone text,
    customer_fax text,
    customer_email text,
    customer_newsletter boolean,
    shop_c_billing_id integer,
    shop_c_billing_number text,
    billing_lastname text,
    billing_firstname text,
    billing_company text,
    billing_street text,
    billing_zipcode text,
    billing_city text,
    billing_country text,
    billing_greeting text,
    billing_department text,
    billing_vat text,
    billing_phone text,
    billing_fax text,
    billing_email text,
    sepa_account_holder text,
    sepa_iban text,
    sepa_bic text,
    shop_c_delivery_id integer,
    shop_c_delivery_number text,
    delivery_lastname text,
    delivery_firstname text,
    delivery_company text,
    delivery_street text,
    delivery_zipcode text,
    delivery_city text,
    delivery_country text,
    delivery_greeting text,
    delivery_department text,
    delivery_vat text,
    delivery_phone text,
    delivery_fax text,
    delivery_email text,
    obsolete boolean DEFAULT false NOT NULL,
    positions integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE SEQUENCE public.shop_orders_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.shop_orders_id_seq OWNED BY public.shop_orders.id;

--
--

CREATE TABLE public.shop_parts (
    id integer NOT NULL,
    shop_id integer NOT NULL,
    part_id integer NOT NULL,
    shop_description text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    last_update timestamp without time zone,
    show_date date,
    sortorder integer,
    front_page boolean DEFAULT false NOT NULL,
    active boolean DEFAULT false NOT NULL,
    shop_category text[],
    active_price_source text,
    metatag_keywords text,
    metatag_description text,
    metatag_title text
);

--
--

CREATE SEQUENCE public.shop_parts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.shop_parts_id_seq OWNED BY public.shop_parts.id;

--
--

CREATE TABLE public.shops (
    id integer NOT NULL,
    description text,
    obsolete boolean DEFAULT false NOT NULL,
    sortkey integer,
    connector text,
    pricetype text,
    price_source text,
    taxzone_id integer,
    last_order_number integer,
    orders_to_fetch integer,
    server text,
    port integer,
    login text,
    password text,
    protocol text DEFAULT 'http'::text NOT NULL,
    path text DEFAULT '/'::text NOT NULL,
    realm text,
    transaction_description text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone DEFAULT now(),
    shipping_costs_parts_id integer,
    use_part_longdescription boolean DEFAULT false,
    proxy text DEFAULT ''::text
);

--
--

CREATE SEQUENCE public.shops_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.shops_id_seq OWNED BY public.shops.id;

--
--

CREATE TABLE public.status (
    trans_id integer,
    formname text,
    printed boolean DEFAULT false,
    emailed boolean DEFAULT false,
    spoolfile text,
    chart_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.status_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.status_id_seq OWNED BY public.status.id;

--
--

CREATE TABLE public.stocktakings (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    inventory_id integer,
    warehouse_id integer NOT NULL,
    bin_id integer NOT NULL,
    parts_id integer NOT NULL,
    employee_id integer NOT NULL,
    qty numeric(25,5) NOT NULL,
    comment text,
    chargenumber text DEFAULT ''::text NOT NULL,
    bestbefore date,
    cutoff_date date NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.tax (
    chart_id integer,
    rate numeric(15,5) DEFAULT 0 NOT NULL,
    taxkey integer NOT NULL,
    taxdescription text NOT NULL,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    chart_categories text NOT NULL,
    skonto_sales_chart_id integer,
    skonto_purchase_chart_id integer,
    reverse_charge_chart_id integer
);

--
--

CREATE TABLE public.tax_zones (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    description text,
    sortkey integer NOT NULL,
    obsolete boolean DEFAULT false
);

--
--

CREATE TABLE public.taxkeys (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    chart_id integer NOT NULL,
    tax_id integer NOT NULL,
    taxkey_id integer NOT NULL,
    pos_ustva integer,
    startdate date NOT NULL
);

--
--

CREATE TABLE public.taxzone_charts (
    id integer NOT NULL,
    taxzone_id integer NOT NULL,
    buchungsgruppen_id integer NOT NULL,
    income_accno_id integer NOT NULL,
    expense_accno_id integer NOT NULL,
    itime timestamp without time zone DEFAULT now()
);

--
--

CREATE SEQUENCE public.taxzone_charts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.taxzone_charts_id_seq OWNED BY public.taxzone_charts.id;

--
--

CREATE TABLE public.time_recording_articles (
    id integer NOT NULL,
    part_id integer NOT NULL,
    "position" integer NOT NULL
);

--
--

CREATE SEQUENCE public.time_recording_articles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.time_recording_articles_id_seq OWNED BY public.time_recording_articles.id;

--
--

CREATE TABLE public.time_recordings (
    id integer NOT NULL,
    customer_id integer NOT NULL,
    project_id integer,
    start_time timestamp without time zone,
    end_time timestamp without time zone,
    description text NOT NULL,
    staff_member_id integer NOT NULL,
    employee_id integer NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    mtime timestamp without time zone DEFAULT now() NOT NULL,
    booked boolean DEFAULT false,
    payroll boolean DEFAULT false,
    part_id integer,
    date date NOT NULL,
    duration integer,
    order_id integer
);

--
--

CREATE SEQUENCE public.time_recordings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.time_recordings_id_seq OWNED BY public.time_recordings.id;

--
--

CREATE TABLE public.todo_user_config (
    employee_id integer NOT NULL,
    show_after_login boolean DEFAULT true,
    show_follow_ups boolean DEFAULT true,
    show_follow_ups_login boolean DEFAULT true,
    show_overdue_sales_quotations boolean DEFAULT true,
    show_overdue_sales_quotations_login boolean DEFAULT true,
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.todo_user_config_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.todo_user_config_id_seq OWNED BY public.todo_user_config.id;

--
--

CREATE TABLE public.transfer_type (
    id integer DEFAULT nextval('public.id'::regclass) NOT NULL,
    direction character varying(10) NOT NULL,
    description text,
    sortkey integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

--
--

CREATE TABLE public.translation (
    parts_id integer,
    language_id integer,
    translation text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    longdescription text,
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.translation_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.translation_id_seq OWNED BY public.translation.id;

--
--

CREATE TABLE public.trigger_information (
    id integer NOT NULL,
    key text NOT NULL,
    value text
);

--
--

CREATE SEQUENCE public.trigger_information_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.trigger_information_id_seq OWNED BY public.trigger_information.id;

--
--

CREATE TABLE public.units (
    name character varying(20) NOT NULL,
    base_unit character varying(20),
    factor numeric(20,5),
    type character varying(20),
    sortkey integer NOT NULL,
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.units_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.units_id_seq OWNED BY public.units.id;

--
--

CREATE TABLE public.units_language (
    unit character varying(20) NOT NULL,
    language_id integer NOT NULL,
    localized character varying(20),
    localized_plural character varying(20),
    id integer NOT NULL
);

--
--

CREATE SEQUENCE public.units_language_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.units_language_id_seq OWNED BY public.units_language.id;

--
--

CREATE TABLE public.user_preferences (
    id integer NOT NULL,
    login text NOT NULL,
    namespace text NOT NULL,
    version numeric(15,5),
    key text NOT NULL,
    value text
);

--
--

CREATE SEQUENCE public.user_preferences_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.user_preferences_id_seq OWNED BY public.user_preferences.id;

--
--

CREATE TABLE public.validity_tokens (
    id integer NOT NULL,
    scope text NOT NULL,
    token text NOT NULL,
    itime timestamp without time zone DEFAULT now() NOT NULL,
    valid_until timestamp without time zone NOT NULL
);

--
--

CREATE SEQUENCE public.validity_tokens_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
--

ALTER SEQUENCE public.validity_tokens_id_seq OWNED BY public.validity_tokens.id;

--
--

CREATE TABLE public.vendor (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    name text NOT NULL,
    department_1 text,
    department_2 text,
    street text,
    zipcode text,
    city text,
    country text,
    contact text,
    phone text,
    fax text,
    homepage text,
    email text,
    notes text,
    taxincluded boolean,
    vendornumber text,
    cc text,
    bcc text,
    business_id integer,
    taxnumber text,
    discount real,
    creditlimit numeric(15,5),
    account_number text,
    bank_code text,
    bank text,
    language text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    obsolete boolean DEFAULT false,
    username text,
    user_password text,
    salesman_id integer,
    v_customer_id text,
    language_id integer,
    payment_id integer,
    taxzone_id integer NOT NULL,
    greeting text,
    ustid text,
    iban text,
    bic text,
    direct_debit boolean DEFAULT false,
    depositor text,
    delivery_term_id integer,
    currency_id integer NOT NULL,
    gln text,
    natural_person boolean DEFAULT false
);

--
--

CREATE TABLE public.warehouse (
    id integer DEFAULT nextval(('id'::text)::regclass) NOT NULL,
    description text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    sortkey integer,
    invalid boolean
);

--
--

CREATE TABLE tax.report_categories (
    id integer NOT NULL,
    description text,
    subdescription text
);

--
--

CREATE TABLE tax.report_headings (
    id integer NOT NULL,
    category_id integer NOT NULL,
    type text,
    description text,
    subdescription text
);

--
--

CREATE TABLE tax.report_variables (
    id integer NOT NULL,
    "position" text NOT NULL,
    heading_id integer,
    description text,
    taxbase text,
    dec_places text,
    valid_from date
);

--
--

ALTER TABLE ONLY public.additional_billing_addresses ALTER COLUMN id SET DEFAULT nextval('public.additional_billing_addresses_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.background_job_histories ALTER COLUMN id SET DEFAULT nextval('public.background_job_histories_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.background_jobs ALTER COLUMN id SET DEFAULT nextval('public.background_jobs_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.bank_transactions ALTER COLUMN id SET DEFAULT nextval('public.bank_transactions_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.contact_departments ALTER COLUMN id SET DEFAULT nextval('public.contact_departments_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.contact_titles ALTER COLUMN id SET DEFAULT nextval('public.contact_titles_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.csv_import_profile_settings ALTER COLUMN id SET DEFAULT nextval('public.csv_import_profile_settings_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.csv_import_profiles ALTER COLUMN id SET DEFAULT nextval('public.csv_import_profiles_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.csv_import_report_rows ALTER COLUMN id SET DEFAULT nextval('public.csv_import_report_rows_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.csv_import_report_status ALTER COLUMN id SET DEFAULT nextval('public.csv_import_report_status_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.csv_import_reports ALTER COLUMN id SET DEFAULT nextval('public.csv_import_reports_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.currencies ALTER COLUMN id SET DEFAULT nextval('public.currencies_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.custom_data_export_queries ALTER COLUMN id SET DEFAULT nextval('public.custom_data_export_queries_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.custom_data_export_query_parameters ALTER COLUMN id SET DEFAULT nextval('public.custom_data_export_query_parameters_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.datev ALTER COLUMN id SET DEFAULT nextval('public.datev_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.defaults ALTER COLUMN id SET DEFAULT nextval('public.defaults_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.email_imports ALTER COLUMN id SET DEFAULT nextval('public.email_imports_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.email_journal ALTER COLUMN id SET DEFAULT nextval('public.email_journal_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.email_journal_attachments ALTER COLUMN id SET DEFAULT nextval('public.email_journal_attachments_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.exchangerate ALTER COLUMN id SET DEFAULT nextval('public.exchangerate_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.file_full_texts ALTER COLUMN id SET DEFAULT nextval('public.file_full_texts_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.files ALTER COLUMN id SET DEFAULT nextval('public.files_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.finanzamt ALTER COLUMN id SET DEFAULT nextval('public.finanzamt_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.follow_up_access ALTER COLUMN id SET DEFAULT nextval('public.follow_up_access_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.follow_up_created_for_employees ALTER COLUMN id SET DEFAULT nextval('public.follow_up_created_for_employees_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.follow_up_done ALTER COLUMN id SET DEFAULT nextval('public.follow_up_done_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.generic_translations ALTER COLUMN id SET DEFAULT nextval('public.generic_translations_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.greetings ALTER COLUMN id SET DEFAULT nextval('public.greetings_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.inventory ALTER COLUMN id SET DEFAULT nextval('public.inventory_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.order_statuses ALTER COLUMN id SET DEFAULT nextval('public.order_statuses_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.part_classifications ALTER COLUMN id SET DEFAULT nextval('public.part_classifications_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.part_customer_prices ALTER COLUMN id SET DEFAULT nextval('public.part_customer_prices_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.parts_price_history ALTER COLUMN id SET DEFAULT nextval('public.parts_price_history_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.price_rule_items ALTER COLUMN id SET DEFAULT nextval('public.price_rule_items_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.price_rules ALTER COLUMN id SET DEFAULT nextval('public.price_rules_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.prices ALTER COLUMN id SET DEFAULT nextval('public.prices_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.project_participants ALTER COLUMN id SET DEFAULT nextval('public.project_participants_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.project_phase_participants ALTER COLUMN id SET DEFAULT nextval('public.project_phase_participants_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.project_phases ALTER COLUMN id SET DEFAULT nextval('public.project_phases_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.project_roles ALTER COLUMN id SET DEFAULT nextval('public.project_roles_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.project_statuses ALTER COLUMN id SET DEFAULT nextval('public.project_status_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.project_types ALTER COLUMN id SET DEFAULT nextval('public.project_types_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.purchase_basket_items ALTER COLUMN id SET DEFAULT nextval('public.purchase_basket_items_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.reclamation_items ALTER COLUMN id SET DEFAULT nextval('public.reclamation_items_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.reclamation_reasons ALTER COLUMN id SET DEFAULT nextval('public.reclamation_reasons_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.record_links ALTER COLUMN id SET DEFAULT nextval('public.record_links_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.record_template_items ALTER COLUMN id SET DEFAULT nextval('public.record_template_items_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.record_templates ALTER COLUMN id SET DEFAULT nextval('public.record_templates_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_acceptance_statuses ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_acceptance_statuses_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_complexities ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_complexities_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_items ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_items_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_orders ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_orders_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_parts ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_parts_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_pictures ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_pictures_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_predefined_texts ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_predefined_texts_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_risks ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_risks_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_statuses ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_statuses_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_text_blocks ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_text_blocks_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_types ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_types_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_spec_versions ALTER COLUMN id SET DEFAULT nextval('public.requirement_spec_versions_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.requirement_specs ALTER COLUMN id SET DEFAULT nextval('public.requirement_specs_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.secrets ALTER COLUMN id SET DEFAULT nextval('public.secrets_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.sepa_export_message_ids ALTER COLUMN id SET DEFAULT nextval('public.sepa_export_message_ids_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.shop_images ALTER COLUMN id SET DEFAULT nextval('public.shop_images_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.shop_order_items ALTER COLUMN id SET DEFAULT nextval('public.shop_order_items_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.shop_orders ALTER COLUMN id SET DEFAULT nextval('public.shop_orders_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.shop_parts ALTER COLUMN id SET DEFAULT nextval('public.shop_parts_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.shops ALTER COLUMN id SET DEFAULT nextval('public.shops_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.status ALTER COLUMN id SET DEFAULT nextval('public.status_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.taxzone_charts ALTER COLUMN id SET DEFAULT nextval('public.taxzone_charts_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.time_recording_articles ALTER COLUMN id SET DEFAULT nextval('public.time_recording_articles_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.time_recordings ALTER COLUMN id SET DEFAULT nextval('public.time_recordings_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.todo_user_config ALTER COLUMN id SET DEFAULT nextval('public.todo_user_config_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.translation ALTER COLUMN id SET DEFAULT nextval('public.translation_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.trigger_information ALTER COLUMN id SET DEFAULT nextval('public.trigger_information_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.units ALTER COLUMN id SET DEFAULT nextval('public.units_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.units_language ALTER COLUMN id SET DEFAULT nextval('public.units_language_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.user_preferences ALTER COLUMN id SET DEFAULT nextval('public.user_preferences_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.validity_tokens ALTER COLUMN id SET DEFAULT nextval('public.validity_tokens_id_seq'::regclass);

--
--

ALTER TABLE ONLY public.acc_trans
    ADD CONSTRAINT acc_trans_pkey PRIMARY KEY (acc_trans_id);

--
--

ALTER TABLE ONLY public.additional_billing_addresses
    ADD CONSTRAINT additional_billing_addresses_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.ap_gl
    ADD CONSTRAINT ap_gl_pkey PRIMARY KEY (ap_id, gl_id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.assembly
    ADD CONSTRAINT assembly_pkey PRIMARY KEY (assembly_id);

--
--

ALTER TABLE ONLY public.assortment_items
    ADD CONSTRAINT assortment_part_pkey PRIMARY KEY (assortment_id, parts_id);

--
--

ALTER TABLE ONLY public.background_job_histories
    ADD CONSTRAINT background_job_histories_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.background_jobs
    ADD CONSTRAINT background_jobs_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.bank_accounts
    ADD CONSTRAINT bank_accounts_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.bank_transaction_acc_trans
    ADD CONSTRAINT bank_transaction_acc_trans_pkey PRIMARY KEY (bank_transaction_id, acc_trans_id);

--
--

ALTER TABLE ONLY public.bank_transactions
    ADD CONSTRAINT bank_transactions_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.bin
    ADD CONSTRAINT bin_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.buchungsgruppen
    ADD CONSTRAINT buchungsgruppen_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.business_models
    ADD CONSTRAINT business_models_pkey PRIMARY KEY (parts_id, business_id);

--
--

ALTER TABLE ONLY public.business
    ADD CONSTRAINT business_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.bank_accounts
    ADD CONSTRAINT chart_id_unique UNIQUE (chart_id);

--
--

ALTER TABLE ONLY public.chart
    ADD CONSTRAINT chart_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.contact_departments
    ADD CONSTRAINT contact_departments_description_key UNIQUE (description);

--
--

ALTER TABLE ONLY public.contact_departments
    ADD CONSTRAINT contact_departments_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.contact_titles
    ADD CONSTRAINT contact_titles_description_key UNIQUE (description);

--
--

ALTER TABLE ONLY public.contact_titles
    ADD CONSTRAINT contact_titles_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.contacts
    ADD CONSTRAINT contacts_pkey PRIMARY KEY (cp_id);

--
--

ALTER TABLE ONLY public.csv_import_profile_settings
    ADD CONSTRAINT csv_import_profile_settings_csv_import_profile_id_key_key UNIQUE (csv_import_profile_id, key);

--
--

ALTER TABLE ONLY public.csv_import_profile_settings
    ADD CONSTRAINT csv_import_profile_settings_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.csv_import_profiles
    ADD CONSTRAINT csv_import_profiles_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.csv_import_report_rows
    ADD CONSTRAINT csv_import_report_rows_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.csv_import_report_status
    ADD CONSTRAINT csv_import_report_status_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.csv_import_reports
    ADD CONSTRAINT csv_import_reports_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.currencies
    ADD CONSTRAINT currencies_name_key UNIQUE (name);

--
--

ALTER TABLE ONLY public.currencies
    ADD CONSTRAINT currencies_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.custom_data_export_queries
    ADD CONSTRAINT custom_data_export_queries_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.custom_data_export_query_parameters
    ADD CONSTRAINT custom_data_export_query_parameters_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.custom_variable_config_partsgroups
    ADD CONSTRAINT custom_variable_config_partsgroups_pkey PRIMARY KEY (custom_variable_config_id, partsgroup_id);

--
--

ALTER TABLE ONLY public.custom_variable_configs
    ADD CONSTRAINT custom_variable_configs_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.custom_variables
    ADD CONSTRAINT custom_variables_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.custom_variables_validity
    ADD CONSTRAINT custom_variables_validity_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.customer
    ADD CONSTRAINT customer_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.datev
    ADD CONSTRAINT datev_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.delivery_order_items
    ADD CONSTRAINT delivery_order_items_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.delivery_order_items_stock
    ADD CONSTRAINT delivery_order_items_stock_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.delivery_terms
    ADD CONSTRAINT delivery_terms_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.department
    ADD CONSTRAINT department_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.drafts
    ADD CONSTRAINT drafts_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.dunning_config
    ADD CONSTRAINT dunning_config_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.dunning
    ADD CONSTRAINT dunning_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.email_imports
    ADD CONSTRAINT email_imports_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.email_journal_attachments
    ADD CONSTRAINT email_journal_attachments_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.email_journal
    ADD CONSTRAINT email_journal_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT employee_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.employee_project_invoices
    ADD CONSTRAINT employee_project_invoices_pkey PRIMARY KEY (employee_id, project_id);

--
--

ALTER TABLE ONLY public.exchangerate
    ADD CONSTRAINT exchangerate_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.file_full_texts
    ADD CONSTRAINT file_full_texts_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.file_versions
    ADD CONSTRAINT file_versions_guid_key UNIQUE (guid);

--
--

ALTER TABLE ONLY public.file_versions
    ADD CONSTRAINT file_versions_pkey PRIMARY KEY (guid);

--
--

ALTER TABLE ONLY public.files
    ADD CONSTRAINT files_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.finanzamt
    ADD CONSTRAINT finanzamt_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.follow_up_access
    ADD CONSTRAINT follow_up_access_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.follow_up_created_for_employees
    ADD CONSTRAINT follow_up_created_for_employees_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.follow_up_done
    ADD CONSTRAINT follow_up_done_follow_up_id_key UNIQUE (follow_up_id);

--
--

ALTER TABLE ONLY public.follow_up_done
    ADD CONSTRAINT follow_up_done_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.follow_up_links
    ADD CONSTRAINT follow_up_links_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.follow_ups
    ADD CONSTRAINT follow_ups_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.generic_translations
    ADD CONSTRAINT generic_translations_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.gl
    ADD CONSTRAINT gl_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.greetings
    ADD CONSTRAINT greetings_description_key UNIQUE (description);

--
--

ALTER TABLE ONLY public.greetings
    ADD CONSTRAINT greetings_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.history_erp
    ADD CONSTRAINT history_erp_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.language
    ADD CONSTRAINT language_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.letter_draft
    ADD CONSTRAINT letter_draft_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.letter
    ADD CONSTRAINT letter_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.makemodel
    ADD CONSTRAINT makemodel_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.notes
    ADD CONSTRAINT notes_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.oe_version
    ADD CONSTRAINT oe_version_pkey PRIMARY KEY (oe_id, version);

--
--

ALTER TABLE ONLY public.order_statuses
    ADD CONSTRAINT order_statuses_name_key UNIQUE (name);

--
--

ALTER TABLE ONLY public.order_statuses
    ADD CONSTRAINT order_statuses_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.part_classifications
    ADD CONSTRAINT part_classifications_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.part_customer_prices
    ADD CONSTRAINT part_customer_prices_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.prices
    ADD CONSTRAINT parts_id_pricegroup_id_unique UNIQUE (parts_id, pricegroup_id);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT parts_partnumber_key1 UNIQUE (partnumber);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT parts_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.parts_price_history
    ADD CONSTRAINT parts_price_history_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.partsgroup
    ADD CONSTRAINT partsgroup_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.payment_terms
    ADD CONSTRAINT payment_terms_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.periodic_invoices_configs
    ADD CONSTRAINT periodic_invoices_configs_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.periodic_invoices
    ADD CONSTRAINT periodic_invoices_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.price_factors
    ADD CONSTRAINT price_factors_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.price_rule_items
    ADD CONSTRAINT price_rule_items_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.price_rules
    ADD CONSTRAINT price_rules_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.pricegroup
    ADD CONSTRAINT pricegroup_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.prices
    ADD CONSTRAINT prices_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.printers
    ADD CONSTRAINT printers_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.project_participants
    ADD CONSTRAINT project_participants_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.project_phase_participants
    ADD CONSTRAINT project_phase_participants_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.project_phases
    ADD CONSTRAINT project_phases_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.project
    ADD CONSTRAINT project_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.project
    ADD CONSTRAINT project_projectnumber_key UNIQUE (projectnumber);

--
--

ALTER TABLE ONLY public.project_roles
    ADD CONSTRAINT project_roles_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.project_statuses
    ADD CONSTRAINT project_status_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.project_types
    ADD CONSTRAINT project_types_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.purchase_basket_items
    ADD CONSTRAINT purchase_basket_items_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.reclamation_items
    ADD CONSTRAINT reclamation_items_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.reclamation_reasons
    ADD CONSTRAINT reclamation_reasons_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.reconciliation_links
    ADD CONSTRAINT reconciliation_links_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.record_links
    ADD CONSTRAINT record_links_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.record_template_items
    ADD CONSTRAINT record_template_items_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_acceptance_statuses
    ADD CONSTRAINT requirement_spec_acceptance_statuses_name_description_key UNIQUE (name, description);

--
--

ALTER TABLE ONLY public.requirement_spec_acceptance_statuses
    ADD CONSTRAINT requirement_spec_acceptance_statuses_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_complexities
    ADD CONSTRAINT requirement_spec_complexities_description_key UNIQUE (description);

--
--

ALTER TABLE ONLY public.requirement_spec_complexities
    ADD CONSTRAINT requirement_spec_complexities_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_orders
    ADD CONSTRAINT requirement_spec_id_order_id_unique UNIQUE (requirement_spec_id, order_id);

--
--

ALTER TABLE ONLY public.requirement_spec_item_dependencies
    ADD CONSTRAINT requirement_spec_item_dependencies_pkey PRIMARY KEY (depending_item_id, depended_item_id);

--
--

ALTER TABLE ONLY public.requirement_spec_items
    ADD CONSTRAINT requirement_spec_items_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_orders
    ADD CONSTRAINT requirement_spec_orders_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_parts
    ADD CONSTRAINT requirement_spec_parts_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_pictures
    ADD CONSTRAINT requirement_spec_pictures_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_predefined_texts
    ADD CONSTRAINT requirement_spec_predefined_texts_description_key UNIQUE (description);

--
--

ALTER TABLE ONLY public.requirement_spec_predefined_texts
    ADD CONSTRAINT requirement_spec_predefined_texts_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_risks
    ADD CONSTRAINT requirement_spec_risks_description_key UNIQUE (description);

--
--

ALTER TABLE ONLY public.requirement_spec_risks
    ADD CONSTRAINT requirement_spec_risks_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_statuses
    ADD CONSTRAINT requirement_spec_statuses_name_description_key UNIQUE (name, description);

--
--

ALTER TABLE ONLY public.requirement_spec_statuses
    ADD CONSTRAINT requirement_spec_statuses_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_text_blocks
    ADD CONSTRAINT requirement_spec_text_blocks_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_types
    ADD CONSTRAINT requirement_spec_types_description_key UNIQUE (description);

--
--

ALTER TABLE ONLY public.requirement_spec_types
    ADD CONSTRAINT requirement_spec_types_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_spec_versions
    ADD CONSTRAINT requirement_spec_versions_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.requirement_specs
    ADD CONSTRAINT requirement_specs_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.schema_info
    ADD CONSTRAINT schema_info_pkey PRIMARY KEY (tag);

--
--

ALTER TABLE ONLY public.secrets
    ADD CONSTRAINT secrets_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.secrets
    ADD CONSTRAINT secrets_tag_key UNIQUE (tag);

--
--

ALTER TABLE ONLY public.sepa_export_items
    ADD CONSTRAINT sepa_export_items_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.sepa_export_message_ids
    ADD CONSTRAINT sepa_export_message_ids_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.sepa_export
    ADD CONSTRAINT sepa_export_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.shipto
    ADD CONSTRAINT shipto_pkey PRIMARY KEY (shipto_id);

--
--

ALTER TABLE ONLY public.shop_images
    ADD CONSTRAINT shop_images_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.shop_order_items
    ADD CONSTRAINT shop_order_items_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.shop_orders
    ADD CONSTRAINT shop_orders_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.shop_parts
    ADD CONSTRAINT shop_parts_part_id_shop_id_key UNIQUE (part_id, shop_id);

--
--

ALTER TABLE ONLY public.shop_parts
    ADD CONSTRAINT shop_parts_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.shops
    ADD CONSTRAINT shops_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.status
    ADD CONSTRAINT status_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.stocktakings
    ADD CONSTRAINT stocktakings_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.tax
    ADD CONSTRAINT tax_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.tax_zones
    ADD CONSTRAINT tax_zones_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.taxkeys
    ADD CONSTRAINT taxkeys_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.taxzone_charts
    ADD CONSTRAINT taxzone_charts_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.time_recording_articles
    ADD CONSTRAINT time_recording_articles_part_id_key UNIQUE (part_id);

--
--

ALTER TABLE ONLY public.time_recording_articles
    ADD CONSTRAINT time_recording_articles_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.time_recordings
    ADD CONSTRAINT time_recordings_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.todo_user_config
    ADD CONSTRAINT todo_user_config_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.transfer_type
    ADD CONSTRAINT transfer_type_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.translation
    ADD CONSTRAINT translation_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.trigger_information
    ADD CONSTRAINT trigger_information_key_value_key UNIQUE (key, value);

--
--

ALTER TABLE ONLY public.trigger_information
    ADD CONSTRAINT trigger_information_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.units
    ADD CONSTRAINT units_id_unique UNIQUE (id);

--
--

ALTER TABLE ONLY public.units_language
    ADD CONSTRAINT units_language_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.units
    ADD CONSTRAINT units_pkey PRIMARY KEY (name);

--
--

ALTER TABLE ONLY public.user_preferences
    ADD CONSTRAINT user_preferences_login_namespace_version_key_key UNIQUE (login, namespace, version, key);

--
--

ALTER TABLE ONLY public.user_preferences
    ADD CONSTRAINT user_preferences_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.validity_tokens
    ADD CONSTRAINT validity_tokens_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.validity_tokens
    ADD CONSTRAINT validity_tokens_scope_token_key UNIQUE (scope, token);

--
--

ALTER TABLE ONLY public.vendor
    ADD CONSTRAINT vendor_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY public.warehouse
    ADD CONSTRAINT warehouse_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY tax.report_categories
    ADD CONSTRAINT report_categorys_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY tax.report_headings
    ADD CONSTRAINT report_headings_pkey PRIMARY KEY (id);

--
--

ALTER TABLE ONLY tax.report_variables
    ADD CONSTRAINT report_variables_pkey PRIMARY KEY (id);

--
--

CREATE INDEX acc_trans_chart_id_key ON public.acc_trans USING btree (chart_id);

--
--

CREATE INDEX acc_trans_source_key ON public.acc_trans USING btree (lower(source));

--
--

CREATE INDEX acc_trans_trans_id_key ON public.acc_trans USING btree (trans_id);

--
--

CREATE INDEX acc_trans_transdate_key ON public.acc_trans USING btree (transdate);

--
--

CREATE INDEX ap_employee_id_key ON public.ap USING btree (employee_id);

--
--

CREATE INDEX ap_invnumber_gin_trgm_idx ON public.ap USING gin (invnumber public.gin_trgm_ops);

--
--

CREATE INDEX ap_invnumber_key ON public.ap USING btree (lower(invnumber));

--
--

CREATE INDEX ap_ordnumber_gin_trgm_idx ON public.ap USING gin (ordnumber public.gin_trgm_ops);

--
--

CREATE INDEX ap_ordnumber_key ON public.ap USING btree (lower(ordnumber));

--
--

CREATE INDEX ap_quonumber_gin_trgm_idx ON public.ap USING gin (quonumber public.gin_trgm_ops);

--
--

CREATE INDEX ap_quonumber_key ON public.ap USING btree (lower(quonumber));

--
--

CREATE INDEX ap_transaction_description_gin_trgm_idx ON public.ap USING gin (transaction_description public.gin_trgm_ops);

--
--

CREATE INDEX ap_transdate_key ON public.ap USING btree (transdate);

--
--

CREATE INDEX ap_vendor_id_key ON public.ap USING btree (vendor_id);

--
--

CREATE INDEX ar_cusordnumber_gin_trgm_idx ON public.ar USING gin (cusordnumber public.gin_trgm_ops);

--
--

CREATE INDEX ar_customer_id_key ON public.ar USING btree (customer_id);

--
--

CREATE INDEX ar_employee_id_key ON public.ar USING btree (employee_id);

--
--

CREATE INDEX ar_invnumber_gin_trgm_idx ON public.ar USING gin (invnumber public.gin_trgm_ops);

--
--

CREATE INDEX ar_invnumber_key ON public.ar USING btree (lower(invnumber));

--
--

CREATE INDEX ar_ordnumber_gin_trgm_idx ON public.ar USING gin (ordnumber public.gin_trgm_ops);

--
--

CREATE INDEX ar_ordnumber_key ON public.ar USING btree (lower(ordnumber));

--
--

CREATE INDEX ar_quonumber_gin_trgm_idx ON public.ar USING gin (quonumber public.gin_trgm_ops);

--
--

CREATE INDEX ar_quonumber_key ON public.ar USING btree (lower(quonumber));

--
--

CREATE INDEX ar_transaction_description_gin_trgm_idx ON public.ar USING gin (transaction_description public.gin_trgm_ops);

--
--

CREATE INDEX ar_transdate_key ON public.ar USING btree (transdate);

--
--

CREATE INDEX assembly_id_key ON public.assembly USING btree (id);

--
--

CREATE UNIQUE INDEX chart_accno_key ON public.chart USING btree (accno);

--
--

CREATE INDEX chart_category_key ON public.chart USING btree (category);

--
--

CREATE INDEX chart_link_key ON public.chart USING btree (link);

--
--

CREATE INDEX csv_import_report_rows_index_row ON public.csv_import_report_rows USING btree ("row");

--
--

CREATE INDEX custom_variables_config_id_idx ON public.custom_variables USING btree (config_id);

--
--

CREATE INDEX custom_variables_sub_module_idx ON public.custom_variables USING btree (sub_module);

--
--

CREATE INDEX custom_variables_trans_config_module_idx ON public.custom_variables USING btree (config_id, trans_id, sub_module);

--
--

CREATE INDEX customer_contact_key ON public.customer USING btree (contact);

--
--

CREATE INDEX customer_customernumber_gin_trgm_idx ON public.customer USING gin (customernumber public.gin_trgm_ops);

--
--

CREATE INDEX customer_customernumber_key ON public.customer USING btree (customernumber);

--
--

CREATE INDEX customer_name_gin_trgm_idx ON public.customer USING gin (name public.gin_trgm_ops);

--
--

CREATE INDEX customer_name_key ON public.customer USING btree (name);

--
--

CREATE INDEX customer_street_gin_trgm_idx ON public.customer USING gin (street public.gin_trgm_ops);

--
--

CREATE INDEX delivery_orders_record_type_key ON public.delivery_orders USING btree (record_type);

--
--

CREATE INDEX do_cusordnumber_gin_trgm_idx ON public.delivery_orders USING gin (cusordnumber public.gin_trgm_ops);

--
--

CREATE INDEX do_donumber_gin_trgm_idx ON public.delivery_orders USING gin (donumber public.gin_trgm_ops);

--
--

CREATE INDEX do_ordnumber_gin_trgm_idx ON public.delivery_orders USING gin (ordnumber public.gin_trgm_ops);

--
--

CREATE INDEX do_transaction_description_gin_trgm_idx ON public.delivery_orders USING gin (transaction_description public.gin_trgm_ops);

--
--

CREATE INDEX doi_description_gin_trgm_idx ON public.delivery_order_items USING gin (description public.gin_trgm_ops);

--
--

CREATE INDEX email_journal_folder_uid_idx ON public.email_journal USING btree (folder, uid);

--
--

CREATE UNIQUE INDEX employee_login_key ON public.employee USING btree (login);

--
--

CREATE INDEX employee_name_key ON public.employee USING btree (name);

--
--

CREATE INDEX file_full_texts_file_id_idx ON public.file_full_texts USING btree (file_id);

--
--

CREATE INDEX file_versions_file_id_idx ON public.file_versions USING btree (file_id);

--
--

CREATE INDEX generic_translations_type_id_idx ON public.generic_translations USING btree (language_id, translation_type, translation_id);

--
--

CREATE INDEX gl_description_gin_trgm_idx ON public.gl USING gin (description public.gin_trgm_ops);

--
--

CREATE INDEX gl_description_key ON public.gl USING btree (lower(description));

--
--

CREATE INDEX gl_employee_id_key ON public.gl USING btree (employee_id);

--
--

CREATE INDEX gl_reference_gin_trgm_idx ON public.gl USING gin (reference public.gin_trgm_ops);

--
--

CREATE INDEX gl_reference_key ON public.gl USING btree (lower(reference));

--
--

CREATE INDEX gl_transdate_key ON public.gl USING btree (transdate);

--
--

CREATE INDEX idx_custom_variables_validity_config_id_trans_id ON public.custom_variables_validity USING btree (config_id, trans_id);

--
--

CREATE INDEX idx_custom_variables_validity_trans_id ON public.custom_variables_validity USING btree (trans_id);

--
--

CREATE INDEX idx_record_links_from_id ON public.record_links USING btree (from_id);

--
--

CREATE INDEX idx_record_links_from_table ON public.record_links USING btree (from_table);

--
--

CREATE INDEX idx_record_links_to_id ON public.record_links USING btree (to_id);

--
--

CREATE INDEX idx_record_links_to_table ON public.record_links USING btree (to_table);

--
--

CREATE INDEX inventory_itime_parts_id_idx ON public.inventory USING btree (itime, parts_id);

--
--

CREATE INDEX inventory_parts_id_idx ON public.inventory USING btree (parts_id);

--
--

CREATE INDEX invoice_description_gin_trgm_idx ON public.invoice USING gin (description public.gin_trgm_ops);

--
--

CREATE INDEX invoice_trans_id_key ON public.invoice USING btree (trans_id);

--
--

CREATE INDEX makemodel_model_key ON public.makemodel USING btree (lower(model));

--
--

CREATE INDEX makemodel_parts_id_key ON public.makemodel USING btree (parts_id);

--
--

CREATE INDEX oe_cusordnumber_gin_trgm_idx ON public.oe USING gin (cusordnumber public.gin_trgm_ops);

--
--

CREATE INDEX oe_employee_id_key ON public.oe USING btree (employee_id);

--
--

CREATE INDEX oe_ordnumber_gin_trgm_idx ON public.oe USING gin (ordnumber public.gin_trgm_ops);

--
--

CREATE INDEX oe_ordnumber_key ON public.oe USING btree (lower(ordnumber));

--
--

CREATE INDEX oe_quonumber_gin_trgm_idx ON public.oe USING gin (quonumber public.gin_trgm_ops);

--
--

CREATE INDEX oe_record_type_key ON public.oe USING btree (record_type);

--
--

CREATE INDEX oe_transaction_description_gin_trgm_idx ON public.oe USING gin (transaction_description public.gin_trgm_ops);

--
--

CREATE INDEX oe_transdate_key ON public.oe USING btree (transdate);

--
--

CREATE INDEX oe_version_file_id_idx ON public.oe_version USING btree (file_id);

--
--

CREATE INDEX orderitems_description_gin_trgm_idx ON public.orderitems USING gin (description public.gin_trgm_ops);

--
--

CREATE INDEX orderitems_trans_id_key ON public.orderitems USING btree (trans_id);

--
--

CREATE INDEX part_customer_prices_customer_id_key ON public.part_customer_prices USING btree (customer_id);

--
--

CREATE INDEX part_customer_prices_parts_id_key ON public.part_customer_prices USING btree (parts_id);

--
--

CREATE INDEX parts_description_gin_trgm_idx ON public.parts USING gin (description public.gin_trgm_ops);

--
--

CREATE INDEX parts_description_key ON public.parts USING btree (lower(description));

--
--

CREATE INDEX parts_partnumber_gin_trgm_idx ON public.parts USING gin (partnumber public.gin_trgm_ops);

--
--

CREATE INDEX parts_partnumber_key ON public.parts USING btree (lower(partnumber));

--
--

CREATE INDEX reclamations_customer_id_key ON public.reclamations USING btree (customer_id);

--
--

CREATE INDEX reclamations_record_number_key ON public.reclamations USING btree (record_number);

--
--

CREATE INDEX reclamations_record_type_key ON public.reclamations USING btree (record_type);

--
--

CREATE INDEX reclamations_vendor_id_key ON public.reclamations USING btree (vendor_id);

--
--

CREATE INDEX requirement_spec_items_item_type_key ON public.requirement_spec_items USING btree (item_type);

--
--

CREATE INDEX shipto_trans_id_key ON public.shipto USING btree (trans_id);

--
--

CREATE INDEX shop_images_file_id_idx ON public.shop_images USING btree (file_id);

--
--

CREATE INDEX status_trans_id_key ON public.status USING btree (trans_id);

--
--

CREATE UNIQUE INDEX taxkeys_chartid_startdate ON public.taxkeys USING btree (chart_id, startdate);

--
--

CREATE INDEX units_language_unit_idx ON public.units_language USING btree (unit);

--
--

CREATE INDEX vendor_contact_key ON public.vendor USING btree (contact);

--
--

CREATE INDEX vendor_name_gin_trgm_idx ON public.vendor USING gin (name public.gin_trgm_ops);

--
--

CREATE INDEX vendor_name_key ON public.vendor USING btree (name);

--
--

CREATE INDEX vendor_vendornumber_gin_trgm_idx ON public.vendor USING gin (vendornumber public.gin_trgm_ops);

--
--

CREATE INDEX vendor_vendornumber_key ON public.vendor USING btree (vendornumber);

--
--

CREATE TRIGGER add_parts_price_history_entry_after_changes_on_parts AFTER INSERT OR UPDATE ON public.parts FOR EACH ROW EXECUTE FUNCTION public.add_parts_price_history_entry();

--
--

CREATE TRIGGER after_delete_ap_trigger AFTER DELETE ON public.ap FOR EACH ROW EXECUTE FUNCTION public.clean_up_acc_trans_after_ar_ap_gl_delete();

--
--

CREATE TRIGGER after_delete_ar_trigger AFTER DELETE ON public.ar FOR EACH ROW EXECUTE FUNCTION public.clean_up_acc_trans_after_ar_ap_gl_delete();

--
--

CREATE TRIGGER after_delete_customer_trigger AFTER DELETE ON public.customer FOR EACH ROW EXECUTE FUNCTION public.clean_up_after_customer_vendor_delete();

--
--

CREATE TRIGGER after_delete_delivery_term_trigger AFTER DELETE ON public.delivery_terms FOR EACH ROW EXECUTE FUNCTION public.generic_translations_delete_on_delivery_terms_delete_trigger();

--
--

CREATE TRIGGER after_delete_gl_trigger AFTER DELETE ON public.gl FOR EACH ROW EXECUTE FUNCTION public.clean_up_acc_trans_after_ar_ap_gl_delete();

--
--

CREATE TRIGGER after_delete_payment_term_trigger AFTER DELETE ON public.payment_terms FOR EACH ROW EXECUTE FUNCTION public.generic_translations_delete_on_payment_terms_delete_trigger();

--
--

CREATE TRIGGER after_delete_requirement_spec_dependencies AFTER DELETE ON public.requirement_specs FOR EACH ROW EXECUTE FUNCTION public.requirement_spec_delete_trigger();

--
--

CREATE TRIGGER after_delete_shop_images_trigger AFTER DELETE ON public.shop_images FOR EACH ROW EXECUTE FUNCTION public.shop_images_reorder_position();

--
--

CREATE TRIGGER after_delete_tax_trigger AFTER DELETE ON public.tax FOR EACH ROW EXECUTE FUNCTION public.generic_translations_delete_on_tax_delete_trigger();

--
--

CREATE TRIGGER after_delete_vendor_trigger AFTER DELETE ON public.vendor FOR EACH ROW EXECUTE FUNCTION public.clean_up_after_customer_vendor_delete();

--
--

CREATE TRIGGER before_delete_ap_trigger BEFORE DELETE ON public.ap FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_ap_delete();

--
--

CREATE TRIGGER before_delete_ar_trigger BEFORE DELETE ON public.ar FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_ar_delete();

--
--

CREATE TRIGGER before_delete_delivery_order_items_trigger BEFORE DELETE ON public.delivery_order_items FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_delivery_order_items_delete();

--
--

CREATE TRIGGER before_delete_delivery_orders_trigger BEFORE DELETE ON public.delivery_orders FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_delivery_orders_delete();

--
--

CREATE TRIGGER before_delete_dunning_trigger BEFORE DELETE ON public.dunning FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_dunning_delete();

--
--

CREATE TRIGGER before_delete_gl_trigger BEFORE DELETE ON public.gl FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_gl_delete();

--
--

CREATE TRIGGER before_delete_invoice_trigger BEFORE DELETE ON public.invoice FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_invoice_delete();

--
--

CREATE TRIGGER before_delete_letter_trigger BEFORE DELETE ON public.letter FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_letter_delete();

--
--

CREATE TRIGGER before_delete_oe_trigger BEFORE DELETE ON public.oe FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_oe_delete();

--
--

CREATE TRIGGER before_delete_orderitems_trigger BEFORE DELETE ON public.orderitems FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_orderitems_delete();

--
--

CREATE TRIGGER before_delete_reclamation_items_clean_up_record_linkes_trigger BEFORE DELETE ON public.reclamation_items FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_delete();

--
--

CREATE TRIGGER before_delete_reclamations_clean_up_record_linkes_trigger BEFORE DELETE ON public.reclamations FOR EACH ROW EXECUTE FUNCTION public.clean_up_record_links_before_delete();

--
--

CREATE TRIGGER check_bin_wh_delivery_order_items_stock BEFORE INSERT OR UPDATE ON public.delivery_order_items_stock FOR EACH ROW EXECUTE FUNCTION public.check_bin_belongs_to_wh();

--
--

CREATE TRIGGER check_bin_wh_inventory BEFORE INSERT OR UPDATE ON public.inventory FOR EACH ROW EXECUTE FUNCTION public.check_bin_belongs_to_wh();

--
--

CREATE TRIGGER check_bin_wh_parts BEFORE INSERT OR UPDATE ON public.parts FOR EACH ROW EXECUTE FUNCTION public.check_bin_belongs_to_wh();

--
--

CREATE TRIGGER contacts_delete_custom_variables_after_deletion AFTER DELETE ON public.contacts FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER customer_before_delete_clear_follow_ups AFTER DELETE ON public.customer FOR EACH ROW EXECUTE FUNCTION public.follow_up_delete_when_customer_vendor_is_deleted_trigger();

--
--

CREATE TRIGGER customer_delete_custom_variables_after_deletion AFTER DELETE ON public.customer FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER delete_delivery_orders_dependencies BEFORE DELETE ON public.delivery_orders FOR EACH ROW EXECUTE FUNCTION public.delivery_orders_before_delete_trigger();

--
--

CREATE TRIGGER delete_oe_dependencies BEFORE DELETE ON public.oe FOR EACH ROW EXECUTE FUNCTION public.oe_before_delete_trigger();

--
--

CREATE TRIGGER delete_requirement_spec_custom_variables BEFORE DELETE ON public.requirement_specs FOR EACH ROW EXECUTE FUNCTION public.delete_requirement_spec_custom_variables_trigger();

--
--

CREATE TRIGGER delete_requirement_spec_dependencies BEFORE DELETE ON public.requirement_specs FOR EACH ROW EXECUTE FUNCTION public.requirement_spec_delete_trigger();

--
--

CREATE TRIGGER delete_requirement_spec_item_dependencies BEFORE DELETE ON public.requirement_spec_items FOR EACH ROW EXECUTE FUNCTION public.requirement_spec_item_before_delete_trigger();

--
--

CREATE TRIGGER delivery_order_items_delete_custom_variables_after_deletion AFTER DELETE ON public.delivery_order_items FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER delivery_orders_on_update_close_follow_up AFTER UPDATE ON public.delivery_orders FOR EACH ROW EXECUTE FUNCTION public.follow_up_close_when_oe_closed_trigger();

--
--

CREATE TRIGGER follow_up_delete_notes AFTER DELETE ON public.follow_ups FOR EACH ROW EXECUTE FUNCTION public.follow_up_delete_notes_trigger();

--
--

CREATE TRIGGER invoice_delete_custom_variables_after_deletion AFTER DELETE ON public.invoice FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER mtime_acc_trans BEFORE UPDATE ON public.acc_trans FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_additional_billing_addresses BEFORE UPDATE ON public.additional_billing_addresses FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_ap BEFORE UPDATE ON public.ap FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_ar BEFORE UPDATE ON public.ar FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_bin BEFORE UPDATE ON public.bin FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_business_models BEFORE UPDATE ON public.business_models FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_chart BEFORE UPDATE ON public.chart FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_contacts BEFORE UPDATE ON public.contacts FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_custom_data_export_queries BEFORE UPDATE ON public.custom_data_export_queries FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_custom_data_export_query_parameters BEFORE UPDATE ON public.custom_data_export_query_parameters FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_custom_variable_config_partsgroups BEFORE UPDATE ON public.custom_variable_config_partsgroups FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_custom_variable_configs BEFORE UPDATE ON public.custom_variable_configs FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_custom_variables BEFORE UPDATE ON public.custom_variables FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_customer BEFORE UPDATE ON public.customer FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_delivery_order_items_id BEFORE UPDATE ON public.delivery_order_items FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_delivery_order_items_stock BEFORE UPDATE ON public.delivery_order_items_stock FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_delivery_orders BEFORE UPDATE ON public.delivery_orders FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_delivery_terms BEFORE UPDATE ON public.delivery_terms FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_department BEFORE UPDATE ON public.department FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_dunning BEFORE UPDATE ON public.dunning FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_email_journal BEFORE UPDATE ON public.email_journal FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_email_journal_attachments BEFORE UPDATE ON public.email_journal_attachments FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_file_full_texts BEFORE UPDATE ON public.file_full_texts FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_file_version BEFORE UPDATE ON public.file_versions FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_follow_up_links BEFORE UPDATE ON public.follow_up_links FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_follow_ups BEFORE UPDATE ON public.follow_ups FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_gl BEFORE UPDATE ON public.gl FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_inventory BEFORE UPDATE ON public.inventory FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_invoice BEFORE UPDATE ON public.invoice FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_notes BEFORE UPDATE ON public.notes FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_oe BEFORE UPDATE ON public.oe FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_oe_version BEFORE UPDATE ON public.oe_version FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_order_statuses BEFORE UPDATE ON public.order_statuses FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_orderitems BEFORE UPDATE ON public.orderitems FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_parts BEFORE UPDATE ON public.parts FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_partsgroup BEFORE UPDATE ON public.partsgroup FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_payment_terms BEFORE UPDATE ON public.payment_terms FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_price_rule_items BEFORE UPDATE ON public.price_rule_items FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_price_rules BEFORE UPDATE ON public.price_rules FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_project BEFORE UPDATE ON public.project FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_project_participants BEFORE UPDATE ON public.project_participants FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_project_phase_paticipants BEFORE UPDATE ON public.project_phase_participants FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_project_phases BEFORE UPDATE ON public.project_phases FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_project_roles BEFORE UPDATE ON public.project_roles FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_project_status BEFORE UPDATE ON public.project_statuses FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_purchase_basket_items BEFORE UPDATE ON public.purchase_basket_items FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_reclamation_items BEFORE UPDATE ON public.reclamation_items FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_reclamation_reasons BEFORE UPDATE ON public.reclamation_reasons FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_reclamations BEFORE UPDATE ON public.reclamations FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_record_templates BEFORE UPDATE ON public.record_templates FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_acceptance_statuses BEFORE UPDATE ON public.requirement_spec_acceptance_statuses FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_complexities BEFORE UPDATE ON public.requirement_spec_complexities FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_items BEFORE UPDATE ON public.requirement_spec_items FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_orders BEFORE UPDATE ON public.requirement_spec_orders FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_pictures BEFORE UPDATE ON public.requirement_spec_pictures FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_predefined_texts BEFORE UPDATE ON public.requirement_spec_predefined_texts FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_risks BEFORE UPDATE ON public.requirement_spec_risks FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_statuses BEFORE UPDATE ON public.requirement_spec_statuses FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_text_blocks BEFORE UPDATE ON public.requirement_spec_text_blocks FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_types BEFORE UPDATE ON public.requirement_spec_types FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_spec_versions BEFORE UPDATE ON public.requirement_spec_versions FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_requirement_specs BEFORE UPDATE ON public.requirement_specs FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_shop_images BEFORE UPDATE ON public.shop_images FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_shop_parts BEFORE UPDATE ON public.shop_parts FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_shops BEFORE UPDATE ON public.shops FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_status BEFORE UPDATE ON public.status FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_stocktakings BEFORE UPDATE ON public.stocktakings FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_tax BEFORE UPDATE ON public.tax FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_time_recordings BEFORE UPDATE ON public.time_recordings FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_transfer_type BEFORE UPDATE ON public.transfer_type FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_vendor BEFORE UPDATE ON public.vendor FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER mtime_warehouse BEFORE UPDATE ON public.warehouse FOR EACH ROW EXECUTE FUNCTION public.set_mtime();

--
--

CREATE TRIGGER oe_before_delete_clear_follow_ups BEFORE DELETE ON public.oe FOR EACH ROW EXECUTE FUNCTION public.follow_up_delete_when_oe_is_deleted_trigger();

--
--

CREATE TRIGGER oe_on_update_close_follow_up AFTER UPDATE ON public.oe FOR EACH ROW EXECUTE FUNCTION public.follow_up_close_when_oe_closed_trigger();

--
--

CREATE TRIGGER orderitems_delete_custom_variables_after_deletion AFTER DELETE ON public.orderitems FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER parts_delete_custom_variables_after_deletion AFTER DELETE ON public.parts FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER project_delete_custom_variables_after_deletion AFTER DELETE ON public.project FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER reclamation_items_delete_custom_variables_after_deletion AFTER DELETE ON public.reclamation_items FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER shipto_delete_custom_variables_after_deletion AFTER DELETE ON public.shipto FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

CREATE TRIGGER time_recordings_set_date BEFORE INSERT OR UPDATE ON public.time_recordings FOR EACH ROW EXECUTE FUNCTION public.time_recordings_set_date_trigger();

--
--

CREATE TRIGGER time_recordings_set_duration BEFORE INSERT OR UPDATE ON public.time_recordings FOR EACH ROW EXECUTE FUNCTION public.time_recordings_set_duration_trigger();

--
--

CREATE TRIGGER trig_update_onhand AFTER INSERT OR DELETE OR UPDATE ON public.inventory FOR EACH ROW EXECUTE FUNCTION public.update_onhand();

--
--

CREATE TRIGGER update_requirement_spec_item_time_estimation AFTER INSERT OR DELETE OR UPDATE ON public.requirement_spec_items FOR EACH ROW EXECUTE FUNCTION public.requirement_spec_item_time_estimation_updater_trigger();

--
--

CREATE TRIGGER vendor_before_delete_clear_follow_ups AFTER DELETE ON public.vendor FOR EACH ROW EXECUTE FUNCTION public.follow_up_delete_when_customer_vendor_is_deleted_trigger();

--
--

CREATE TRIGGER vendor_delete_custom_variables_after_deletion AFTER DELETE ON public.vendor FOR EACH ROW EXECUTE FUNCTION public.delete_custom_variables_trigger();

--
--

ALTER TABLE ONLY public.acc_trans
    ADD CONSTRAINT "$1" FOREIGN KEY (chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT "$1" FOREIGN KEY (vendor_id) REFERENCES public.vendor(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT "$1" FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT "$1" FOREIGN KEY (parts_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT "$1" FOREIGN KEY (buchungsgruppen_id) REFERENCES public.buchungsgruppen(id);

--
--

ALTER TABLE ONLY public.units
    ADD CONSTRAINT "$1" FOREIGN KEY (base_unit) REFERENCES public.units(name);

--
--

ALTER TABLE ONLY public.acc_trans
    ADD CONSTRAINT acc_trans_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.acc_trans
    ADD CONSTRAINT acc_trans_tax_id_fkey FOREIGN KEY (tax_id) REFERENCES public.tax(id);

--
--

ALTER TABLE ONLY public.additional_billing_addresses
    ADD CONSTRAINT additional_billing_addresses_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_cp_id_fkey FOREIGN KEY (cp_id) REFERENCES public.contacts(cp_id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_delivery_term_id_fkey FOREIGN KEY (delivery_term_id) REFERENCES public.delivery_terms(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.ap_gl
    ADD CONSTRAINT ap_gl_ap_id_fkey FOREIGN KEY (ap_id) REFERENCES public.ap(id);

--
--

ALTER TABLE ONLY public.ap_gl
    ADD CONSTRAINT ap_gl_gl_id_fkey FOREIGN KEY (gl_id) REFERENCES public.gl(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_globalproject_id_fkey FOREIGN KEY (globalproject_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_storno_id_fkey FOREIGN KEY (storno_id) REFERENCES public.ap(id);

--
--

ALTER TABLE ONLY public.ap
    ADD CONSTRAINT ap_taxzone_id_fkey FOREIGN KEY (taxzone_id) REFERENCES public.tax_zones(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_billing_address_id_fkey FOREIGN KEY (billing_address_id) REFERENCES public.additional_billing_addresses(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_cp_id_fkey FOREIGN KEY (cp_id) REFERENCES public.contacts(cp_id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_delivery_term_id_fkey FOREIGN KEY (delivery_term_id) REFERENCES public.delivery_terms(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_dunning_config_id_fkey FOREIGN KEY (dunning_config_id) REFERENCES public.dunning_config(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_globalproject_id_fkey FOREIGN KEY (globalproject_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_salesman_id_fkey FOREIGN KEY (salesman_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_shipto_id_fkey FOREIGN KEY (shipto_id) REFERENCES public.shipto(shipto_id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_storno_id_fkey FOREIGN KEY (storno_id) REFERENCES public.ar(id);

--
--

ALTER TABLE ONLY public.ar
    ADD CONSTRAINT ar_taxzone_id_fkey FOREIGN KEY (taxzone_id) REFERENCES public.tax_zones(id);

--
--

ALTER TABLE ONLY public.assembly
    ADD CONSTRAINT assembly_id_fkey FOREIGN KEY (id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.assembly
    ADD CONSTRAINT assembly_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.assortment_items
    ADD CONSTRAINT assortment_items_assortment_id_fkey FOREIGN KEY (assortment_id) REFERENCES public.parts(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.assortment_items
    ADD CONSTRAINT assortment_items_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.assortment_items
    ADD CONSTRAINT assortment_items_unit_fkey FOREIGN KEY (unit) REFERENCES public.units(name);

--
--

ALTER TABLE ONLY public.bank_accounts
    ADD CONSTRAINT bank_accounts_chart_id_fkey FOREIGN KEY (chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.bank_transaction_acc_trans
    ADD CONSTRAINT bank_transaction_acc_trans_acc_trans_id_fkey FOREIGN KEY (acc_trans_id) REFERENCES public.acc_trans(acc_trans_id);

--
--

ALTER TABLE ONLY public.bank_transaction_acc_trans
    ADD CONSTRAINT bank_transaction_acc_trans_ap_id_fkey FOREIGN KEY (ap_id) REFERENCES public.ap(id);

--
--

ALTER TABLE ONLY public.bank_transaction_acc_trans
    ADD CONSTRAINT bank_transaction_acc_trans_ar_id_fkey FOREIGN KEY (ar_id) REFERENCES public.ar(id);

--
--

ALTER TABLE ONLY public.bank_transaction_acc_trans
    ADD CONSTRAINT bank_transaction_acc_trans_bank_transaction_id_fkey FOREIGN KEY (bank_transaction_id) REFERENCES public.bank_transactions(id);

--
--

ALTER TABLE ONLY public.bank_transaction_acc_trans
    ADD CONSTRAINT bank_transaction_acc_trans_gl_id_fkey FOREIGN KEY (gl_id) REFERENCES public.gl(id);

--
--

ALTER TABLE ONLY public.bank_transactions
    ADD CONSTRAINT bank_transactions_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.bank_transactions
    ADD CONSTRAINT bank_transactions_local_bank_account_id_fkey FOREIGN KEY (local_bank_account_id) REFERENCES public.bank_accounts(id);

--
--

ALTER TABLE ONLY public.bin
    ADD CONSTRAINT bin_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouse(id);

--
--

ALTER TABLE ONLY public.buchungsgruppen
    ADD CONSTRAINT buchungsgruppen_inventory_accno_id_fkey FOREIGN KEY (inventory_accno_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.business_models
    ADD CONSTRAINT business_models_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.business(id);

--
--

ALTER TABLE ONLY public.business_models
    ADD CONSTRAINT business_models_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.csv_import_profile_settings
    ADD CONSTRAINT csv_import_profile_settings_csv_import_profile_id_fkey FOREIGN KEY (csv_import_profile_id) REFERENCES public.csv_import_profiles(id);

--
--

ALTER TABLE ONLY public.csv_import_report_rows
    ADD CONSTRAINT csv_import_report_rows_csv_import_report_id_fkey FOREIGN KEY (csv_import_report_id) REFERENCES public.csv_import_reports(id);

--
--

ALTER TABLE ONLY public.csv_import_report_status
    ADD CONSTRAINT csv_import_report_status_csv_import_report_id_fkey FOREIGN KEY (csv_import_report_id) REFERENCES public.csv_import_reports(id);

--
--

ALTER TABLE ONLY public.csv_import_reports
    ADD CONSTRAINT csv_import_reports_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES public.csv_import_profiles(id);

--
--

ALTER TABLE ONLY public.custom_data_export_query_parameters
    ADD CONSTRAINT custom_data_export_query_parameters_query_id_fkey FOREIGN KEY (query_id) REFERENCES public.custom_data_export_queries(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.custom_variable_config_partsgroups
    ADD CONSTRAINT custom_variable_config_partsgrou_custom_variable_config_id_fkey FOREIGN KEY (custom_variable_config_id) REFERENCES public.custom_variable_configs(id);

--
--

ALTER TABLE ONLY public.custom_variable_config_partsgroups
    ADD CONSTRAINT custom_variable_config_partsgroups_partsgroup_id_fkey FOREIGN KEY (partsgroup_id) REFERENCES public.partsgroup(id);

--
--

ALTER TABLE ONLY public.custom_variables
    ADD CONSTRAINT custom_variables_config_id_fkey FOREIGN KEY (config_id) REFERENCES public.custom_variable_configs(id);

--
--

ALTER TABLE ONLY public.custom_variables_validity
    ADD CONSTRAINT custom_variables_validity_config_id_fkey FOREIGN KEY (config_id) REFERENCES public.custom_variable_configs(id);

--
--

ALTER TABLE ONLY public.customer
    ADD CONSTRAINT customer_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.business(id);

--
--

ALTER TABLE ONLY public.customer
    ADD CONSTRAINT customer_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.customer
    ADD CONSTRAINT customer_delivery_term_id_fkey FOREIGN KEY (delivery_term_id) REFERENCES public.delivery_terms(id);

--
--

ALTER TABLE ONLY public.customer
    ADD CONSTRAINT customer_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.customer
    ADD CONSTRAINT customer_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.customer
    ADD CONSTRAINT customer_pricegroup_id_fkey FOREIGN KEY (pricegroup_id) REFERENCES public.pricegroup(id);

--
--

ALTER TABLE ONLY public.customer
    ADD CONSTRAINT customer_taxzone_id_fkey FOREIGN KEY (taxzone_id) REFERENCES public.tax_zones(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_ap_chart_id_fkey FOREIGN KEY (ap_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_ar_chart_id_fkey FOREIGN KEY (ar_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_bin_id_fkey FOREIGN KEY (bin_id) REFERENCES public.bin(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_bin_id_ignore_onhand_fkey FOREIGN KEY (bin_id_ignore_onhand) REFERENCES public.bin(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_carry_over_account_chart_id_fkey FOREIGN KEY (carry_over_account_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_loss_carried_forward_chart_id_fkey FOREIGN KEY (loss_carried_forward_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_profit_carried_forward_chart_id_fkey FOREIGN KEY (profit_carried_forward_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_project_status_id_fkey FOREIGN KEY (project_status_id) REFERENCES public.project_statuses(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_project_type_id_fkey FOREIGN KEY (project_type_id) REFERENCES public.project_types(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_requirement_spec_section_order_part_id_fkey FOREIGN KEY (requirement_spec_section_order_part_id) REFERENCES public.parts(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_stocktaking_bin_id_fkey FOREIGN KEY (stocktaking_bin_id) REFERENCES public.bin(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_stocktaking_warehouse_id_fkey FOREIGN KEY (stocktaking_warehouse_id) REFERENCES public.warehouse(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouse(id);

--
--

ALTER TABLE ONLY public.defaults
    ADD CONSTRAINT defaults_warehouse_id_ignore_onhand_fkey FOREIGN KEY (warehouse_id_ignore_onhand) REFERENCES public.warehouse(id);

--
--

ALTER TABLE ONLY public.delivery_order_items
    ADD CONSTRAINT delivery_order_items_delivery_order_id_fkey FOREIGN KEY (delivery_order_id) REFERENCES public.delivery_orders(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.delivery_order_items
    ADD CONSTRAINT delivery_order_items_orderer_id_fkey FOREIGN KEY (orderer_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.delivery_order_items
    ADD CONSTRAINT delivery_order_items_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.delivery_order_items
    ADD CONSTRAINT delivery_order_items_price_factor_id_fkey FOREIGN KEY (price_factor_id) REFERENCES public.price_factors(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.delivery_order_items
    ADD CONSTRAINT delivery_order_items_pricegroup_id_fkey FOREIGN KEY (pricegroup_id) REFERENCES public.pricegroup(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.delivery_order_items
    ADD CONSTRAINT delivery_order_items_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.delivery_order_items_stock
    ADD CONSTRAINT delivery_order_items_stock_bin_id_fkey FOREIGN KEY (bin_id) REFERENCES public.bin(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.delivery_order_items_stock
    ADD CONSTRAINT delivery_order_items_stock_delivery_order_item_id_fkey FOREIGN KEY (delivery_order_item_id) REFERENCES public.delivery_order_items(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT delivery_order_items_stock_id_fkey FOREIGN KEY (delivery_order_items_stock_id) REFERENCES public.delivery_order_items_stock(id);

--
--

ALTER TABLE ONLY public.delivery_order_items_stock
    ADD CONSTRAINT delivery_order_items_stock_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouse(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.delivery_order_items
    ADD CONSTRAINT delivery_order_items_unit_fkey FOREIGN KEY (unit) REFERENCES public.units(name);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_billing_address_id_fkey FOREIGN KEY (billing_address_id) REFERENCES public.additional_billing_addresses(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_cp_id_fkey FOREIGN KEY (cp_id) REFERENCES public.contacts(cp_id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_delivery_term_id_fkey FOREIGN KEY (delivery_term_id) REFERENCES public.delivery_terms(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_globalproject_id_fkey FOREIGN KEY (globalproject_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_salesman_id_fkey FOREIGN KEY (salesman_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_shipto_id_fkey FOREIGN KEY (shipto_id) REFERENCES public.shipto(shipto_id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_taxzone_id_fkey FOREIGN KEY (taxzone_id) REFERENCES public.tax_zones(id);

--
--

ALTER TABLE ONLY public.delivery_orders
    ADD CONSTRAINT delivery_orders_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendor(id);

--
--

ALTER TABLE ONLY public.drafts
    ADD CONSTRAINT drafts_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.dunning
    ADD CONSTRAINT dunning_dunning_config_id_fkey FOREIGN KEY (dunning_config_id) REFERENCES public.dunning_config(id);

--
--

ALTER TABLE ONLY public.dunning
    ADD CONSTRAINT dunning_fee_interest_ar_id_fkey FOREIGN KEY (fee_interest_ar_id) REFERENCES public.ar(id);

--
--

ALTER TABLE ONLY public.dunning
    ADD CONSTRAINT dunning_trans_id_fkey FOREIGN KEY (trans_id) REFERENCES public.ar(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.email_journal_attachments
    ADD CONSTRAINT email_journal_attachments_email_journal_id_fkey FOREIGN KEY (email_journal_id) REFERENCES public.email_journal(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.email_journal
    ADD CONSTRAINT email_journal_email_import_id_fkey FOREIGN KEY (email_import_id) REFERENCES public.email_imports(id);

--
--

ALTER TABLE ONLY public.email_journal
    ADD CONSTRAINT email_journal_sender_id_fkey FOREIGN KEY (sender_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.employee_project_invoices
    ADD CONSTRAINT employee_project_invoices_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.employee_project_invoices
    ADD CONSTRAINT employee_project_invoices_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.exchangerate
    ADD CONSTRAINT exchangerate_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.file_full_texts
    ADD CONSTRAINT file_full_texts_file_id_fkey FOREIGN KEY (file_id) REFERENCES public.files(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.file_versions
    ADD CONSTRAINT file_versions_file_id_fkey FOREIGN KEY (file_id) REFERENCES public.files(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.follow_up_access
    ADD CONSTRAINT follow_up_access_what_fkey FOREIGN KEY (what) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.follow_up_access
    ADD CONSTRAINT follow_up_access_who_fkey FOREIGN KEY (who) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.follow_up_created_for_employees
    ADD CONSTRAINT follow_up_created_for_employees_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.follow_up_created_for_employees
    ADD CONSTRAINT follow_up_created_for_employees_follow_up_id_fkey FOREIGN KEY (follow_up_id) REFERENCES public.follow_ups(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.follow_up_done
    ADD CONSTRAINT follow_up_done_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.follow_up_done
    ADD CONSTRAINT follow_up_done_follow_up_id_fkey FOREIGN KEY (follow_up_id) REFERENCES public.follow_ups(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.follow_up_links
    ADD CONSTRAINT follow_up_links_follow_up_id_fkey FOREIGN KEY (follow_up_id) REFERENCES public.follow_ups(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.follow_ups
    ADD CONSTRAINT follow_ups_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.follow_ups
    ADD CONSTRAINT follow_ups_note_id_fkey FOREIGN KEY (note_id) REFERENCES public.notes(id);

--
--

ALTER TABLE ONLY public.generic_translations
    ADD CONSTRAINT generic_translations_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.gl
    ADD CONSTRAINT gl_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id);

--
--

ALTER TABLE ONLY public.gl
    ADD CONSTRAINT gl_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.gl
    ADD CONSTRAINT gl_storno_id_fkey FOREIGN KEY (storno_id) REFERENCES public.gl(id);

--
--

ALTER TABLE ONLY public.history_erp
    ADD CONSTRAINT history_erp_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_bin_id_fkey FOREIGN KEY (bin_id) REFERENCES public.bin(id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.invoice(id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_trans_type_id_fkey FOREIGN KEY (trans_type_id) REFERENCES public.transfer_type(id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouse(id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_expense_chart_id_fkey FOREIGN KEY (expense_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_inventory_chart_id_fkey FOREIGN KEY (inventory_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_price_factor_id_fkey FOREIGN KEY (price_factor_id) REFERENCES public.price_factors(id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_pricegroup_id_fkey FOREIGN KEY (pricegroup_id) REFERENCES public.pricegroup(id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_tax_id_fkey FOREIGN KEY (tax_id) REFERENCES public.tax(id);

--
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_unit_fkey FOREIGN KEY (unit) REFERENCES public.units(name);

--
--

ALTER TABLE ONLY public.letter
    ADD CONSTRAINT letter_cp_id_fkey FOREIGN KEY (cp_id) REFERENCES public.contacts(cp_id);

--
--

ALTER TABLE ONLY public.letter
    ADD CONSTRAINT letter_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.letter_draft
    ADD CONSTRAINT letter_draft_cp_id_fkey FOREIGN KEY (cp_id) REFERENCES public.contacts(cp_id);

--
--

ALTER TABLE ONLY public.letter_draft
    ADD CONSTRAINT letter_draft_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.letter_draft
    ADD CONSTRAINT letter_draft_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.letter_draft
    ADD CONSTRAINT letter_draft_salesman_id_fkey FOREIGN KEY (salesman_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.letter_draft
    ADD CONSTRAINT letter_draft_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendor(id);

--
--

ALTER TABLE ONLY public.letter
    ADD CONSTRAINT letter_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.letter
    ADD CONSTRAINT letter_salesman_id_fkey FOREIGN KEY (salesman_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.letter
    ADD CONSTRAINT letter_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendor(id);

--
--

ALTER TABLE ONLY public.makemodel
    ADD CONSTRAINT makemodel_make_fkey FOREIGN KEY (make) REFERENCES public.vendor(id);

--
--

ALTER TABLE ONLY public.notes
    ADD CONSTRAINT notes_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_billing_address_id_fkey FOREIGN KEY (billing_address_id) REFERENCES public.additional_billing_addresses(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_cp_id_fkey FOREIGN KEY (cp_id) REFERENCES public.contacts(cp_id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_delivery_customer_id_fkey FOREIGN KEY (delivery_customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_delivery_term_id_fkey FOREIGN KEY (delivery_term_id) REFERENCES public.delivery_terms(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_delivery_vendor_id_fkey FOREIGN KEY (delivery_vendor_id) REFERENCES public.vendor(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_globalproject_id_fkey FOREIGN KEY (globalproject_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT oe_id_fkey FOREIGN KEY (oe_id) REFERENCES public.delivery_orders(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_order_status_id_fkey FOREIGN KEY (order_status_id) REFERENCES public.order_statuses(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_salesman_id_fkey FOREIGN KEY (salesman_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_shipto_id_fkey FOREIGN KEY (shipto_id) REFERENCES public.shipto(shipto_id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_taxzone_id_fkey FOREIGN KEY (taxzone_id) REFERENCES public.tax_zones(id);

--
--

ALTER TABLE ONLY public.oe
    ADD CONSTRAINT oe_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendor(id);

--
--

ALTER TABLE ONLY public.oe_version
    ADD CONSTRAINT oe_version_email_journal_id_fkey FOREIGN KEY (email_journal_id) REFERENCES public.email_journal(id);

--
--

ALTER TABLE ONLY public.oe_version
    ADD CONSTRAINT oe_version_file_id_fkey FOREIGN KEY (file_id) REFERENCES public.files(id);

--
--

ALTER TABLE ONLY public.oe_version
    ADD CONSTRAINT oe_version_oe_id_fkey FOREIGN KEY (oe_id) REFERENCES public.oe(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_orderer_id_fkey FOREIGN KEY (orderer_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_price_factor_id_fkey FOREIGN KEY (price_factor_id) REFERENCES public.price_factors(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_pricegroup_id_fkey FOREIGN KEY (pricegroup_id) REFERENCES public.pricegroup(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_recurring_billing_invoice_id_fkey FOREIGN KEY (recurring_billing_invoice_id) REFERENCES public.ar(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_trans_id_fkey FOREIGN KEY (trans_id) REFERENCES public.oe(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.orderitems
    ADD CONSTRAINT orderitems_unit_fkey FOREIGN KEY (unit) REFERENCES public.units(name);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT part_classification_id_fkey FOREIGN KEY (classification_id) REFERENCES public.part_classifications(id);

--
--

ALTER TABLE ONLY public.part_customer_prices
    ADD CONSTRAINT part_customer_prices_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.part_customer_prices
    ADD CONSTRAINT part_customer_prices_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT parts_bin_id_fkey FOREIGN KEY (bin_id) REFERENCES public.bin(id);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT parts_partsgroup_id_fkey FOREIGN KEY (partsgroup_id) REFERENCES public.partsgroup(id);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT parts_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT parts_price_factor_id_fkey FOREIGN KEY (price_factor_id) REFERENCES public.price_factors(id);

--
--

ALTER TABLE ONLY public.parts_price_history
    ADD CONSTRAINT parts_price_history_part_id_fkey FOREIGN KEY (part_id) REFERENCES public.parts(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT parts_unit_fkey FOREIGN KEY (unit) REFERENCES public.units(name);

--
--

ALTER TABLE ONLY public.parts
    ADD CONSTRAINT parts_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouse(id);

--
--

ALTER TABLE ONLY public.periodic_invoices
    ADD CONSTRAINT periodic_invoices_ar_id_fkey FOREIGN KEY (ar_id) REFERENCES public.ar(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.periodic_invoices
    ADD CONSTRAINT periodic_invoices_config_id_fkey FOREIGN KEY (config_id) REFERENCES public.periodic_invoices_configs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.periodic_invoices_configs
    ADD CONSTRAINT periodic_invoices_configs_ar_chart_id_fkey FOREIGN KEY (ar_chart_id) REFERENCES public.chart(id) ON DELETE RESTRICT;

--
--

ALTER TABLE ONLY public.periodic_invoices_configs
    ADD CONSTRAINT periodic_invoices_configs_email_recipient_contact_id_fkey FOREIGN KEY (email_recipient_contact_id) REFERENCES public.contacts(cp_id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.periodic_invoices_configs
    ADD CONSTRAINT periodic_invoices_configs_oe_id_fkey FOREIGN KEY (oe_id) REFERENCES public.oe(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.periodic_invoices_configs
    ADD CONSTRAINT periodic_invoices_configs_printer_id_fkey FOREIGN KEY (printer_id) REFERENCES public.printers(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.price_rule_items
    ADD CONSTRAINT price_rule_items_custom_variable_configs_id_fkey FOREIGN KEY (custom_variable_configs_id) REFERENCES public.custom_variable_configs(id);

--
--

ALTER TABLE ONLY public.price_rule_items
    ADD CONSTRAINT price_rule_items_price_rules_id_fkey FOREIGN KEY (price_rules_id) REFERENCES public.price_rules(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.prices
    ADD CONSTRAINT prices_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.prices
    ADD CONSTRAINT prices_pricegroup_id_fkey FOREIGN KEY (pricegroup_id) REFERENCES public.pricegroup(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.project
    ADD CONSTRAINT project_billable_customer_id_fkey FOREIGN KEY (billable_customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.project
    ADD CONSTRAINT project_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.project_participants
    ADD CONSTRAINT project_participants_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.project_participants
    ADD CONSTRAINT project_participants_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.project_participants
    ADD CONSTRAINT project_participants_project_role_id_fkey FOREIGN KEY (project_role_id) REFERENCES public.project_roles(id);

--
--

ALTER TABLE ONLY public.project_phase_participants
    ADD CONSTRAINT project_phase_participants_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.project_phase_participants
    ADD CONSTRAINT project_phase_participants_project_phase_id_fkey FOREIGN KEY (project_phase_id) REFERENCES public.project_phases(id);

--
--

ALTER TABLE ONLY public.project_phase_participants
    ADD CONSTRAINT project_phase_participants_project_role_id_fkey FOREIGN KEY (project_role_id) REFERENCES public.project_roles(id);

--
--

ALTER TABLE ONLY public.project_phases
    ADD CONSTRAINT project_phases_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.project
    ADD CONSTRAINT project_project_status_id_fkey FOREIGN KEY (project_status_id) REFERENCES public.project_statuses(id);

--
--

ALTER TABLE ONLY public.project
    ADD CONSTRAINT project_project_type_id_fkey FOREIGN KEY (project_type_id) REFERENCES public.project_types(id);

--
--

ALTER TABLE ONLY public.purchase_basket_items
    ADD CONSTRAINT purchase_basket_items_orderer_id_fkey FOREIGN KEY (orderer_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.purchase_basket_items
    ADD CONSTRAINT purchase_basket_items_part_id_fkey FOREIGN KEY (part_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.reclamation_items
    ADD CONSTRAINT reclamation_items_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.reclamation_items
    ADD CONSTRAINT reclamation_items_price_factor_id_fkey FOREIGN KEY (price_factor_id) REFERENCES public.price_factors(id);

--
--

ALTER TABLE ONLY public.reclamation_items
    ADD CONSTRAINT reclamation_items_pricegroup_id_fkey FOREIGN KEY (pricegroup_id) REFERENCES public.pricegroup(id);

--
--

ALTER TABLE ONLY public.reclamation_items
    ADD CONSTRAINT reclamation_items_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.reclamation_items
    ADD CONSTRAINT reclamation_items_reason_id_fkey FOREIGN KEY (reason_id) REFERENCES public.reclamation_reasons(id);

--
--

ALTER TABLE ONLY public.reclamation_items
    ADD CONSTRAINT reclamation_items_reclamation_id_fkey FOREIGN KEY (reclamation_id) REFERENCES public.reclamations(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.reclamation_items
    ADD CONSTRAINT reclamation_items_unit_fkey FOREIGN KEY (unit) REFERENCES public.units(name);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_billing_address_id_fkey FOREIGN KEY (billing_address_id) REFERENCES public.additional_billing_addresses(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_contact_id_fkey FOREIGN KEY (contact_id) REFERENCES public.contacts(cp_id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_delivery_term_id_fkey FOREIGN KEY (delivery_term_id) REFERENCES public.delivery_terms(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_globalproject_id_fkey FOREIGN KEY (globalproject_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_salesman_id_fkey FOREIGN KEY (salesman_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_shipto_id_fkey FOREIGN KEY (shipto_id) REFERENCES public.shipto(shipto_id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_taxzone_id_fkey FOREIGN KEY (taxzone_id) REFERENCES public.tax_zones(id);

--
--

ALTER TABLE ONLY public.reclamations
    ADD CONSTRAINT reclamations_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendor(id);

--
--

ALTER TABLE ONLY public.reconciliation_links
    ADD CONSTRAINT reconciliation_links_acc_trans_id_fkey FOREIGN KEY (acc_trans_id) REFERENCES public.acc_trans(acc_trans_id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.reconciliation_links
    ADD CONSTRAINT reconciliation_links_bank_transaction_id FOREIGN KEY (bank_transaction_id) REFERENCES public.bank_transactions(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.record_template_items
    ADD CONSTRAINT record_template_items_chart_id FOREIGN KEY (chart_id) REFERENCES public.chart(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.record_template_items
    ADD CONSTRAINT record_template_items_project_id FOREIGN KEY (project_id) REFERENCES public.project(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.record_template_items
    ADD CONSTRAINT record_template_items_record_template_id FOREIGN KEY (record_template_id) REFERENCES public.record_templates(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.record_template_items
    ADD CONSTRAINT record_template_items_tax_id FOREIGN KEY (tax_id) REFERENCES public.tax(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_ar_ap_chart_id_fkey FOREIGN KEY (ar_ap_chart_id) REFERENCES public.chart(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.record_templates
    ADD CONSTRAINT record_templates_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendor(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.requirement_spec_item_dependencies
    ADD CONSTRAINT requirement_spec_item_dependencies_depended_item_id_fkey FOREIGN KEY (depended_item_id) REFERENCES public.requirement_spec_items(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_item_dependencies
    ADD CONSTRAINT requirement_spec_item_dependencies_depending_item_id_fkey FOREIGN KEY (depending_item_id) REFERENCES public.requirement_spec_items(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_items
    ADD CONSTRAINT requirement_spec_items_acceptance_status_id_fkey FOREIGN KEY (acceptance_status_id) REFERENCES public.requirement_spec_acceptance_statuses(id);

--
--

ALTER TABLE ONLY public.requirement_spec_items
    ADD CONSTRAINT requirement_spec_items_complexity_id_fkey FOREIGN KEY (complexity_id) REFERENCES public.requirement_spec_complexities(id);

--
--

ALTER TABLE ONLY public.requirement_spec_items
    ADD CONSTRAINT requirement_spec_items_order_part_id_fkey FOREIGN KEY (order_part_id) REFERENCES public.parts(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.requirement_spec_items
    ADD CONSTRAINT requirement_spec_items_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES public.requirement_spec_items(id);

--
--

ALTER TABLE ONLY public.requirement_spec_items
    ADD CONSTRAINT requirement_spec_items_requirement_spec_id_fkey FOREIGN KEY (requirement_spec_id) REFERENCES public.requirement_specs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_items
    ADD CONSTRAINT requirement_spec_items_risk_id_fkey FOREIGN KEY (risk_id) REFERENCES public.requirement_spec_risks(id);

--
--

ALTER TABLE ONLY public.requirement_spec_orders
    ADD CONSTRAINT requirement_spec_orders_order_id_fkey FOREIGN KEY (order_id) REFERENCES public.oe(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_orders
    ADD CONSTRAINT requirement_spec_orders_requirement_spec_id_fkey FOREIGN KEY (requirement_spec_id) REFERENCES public.requirement_specs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_orders
    ADD CONSTRAINT requirement_spec_orders_version_id_fkey FOREIGN KEY (version_id) REFERENCES public.requirement_spec_versions(id) ON DELETE SET NULL;

--
--

ALTER TABLE ONLY public.requirement_spec_parts
    ADD CONSTRAINT requirement_spec_parts_part_id_fkey FOREIGN KEY (part_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.requirement_spec_parts
    ADD CONSTRAINT requirement_spec_parts_requirement_spec_id_fkey FOREIGN KEY (requirement_spec_id) REFERENCES public.requirement_specs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_parts
    ADD CONSTRAINT requirement_spec_parts_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES public.units(id);

--
--

ALTER TABLE ONLY public.requirement_spec_pictures
    ADD CONSTRAINT requirement_spec_pictures_requirement_spec_id_fkey FOREIGN KEY (requirement_spec_id) REFERENCES public.requirement_specs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_pictures
    ADD CONSTRAINT requirement_spec_pictures_text_block_id_fkey FOREIGN KEY (text_block_id) REFERENCES public.requirement_spec_text_blocks(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_text_blocks
    ADD CONSTRAINT requirement_spec_text_blocks_requirement_spec_id_fkey FOREIGN KEY (requirement_spec_id) REFERENCES public.requirement_specs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_versions
    ADD CONSTRAINT requirement_spec_versions_requirement_spec_id_fkey FOREIGN KEY (requirement_spec_id) REFERENCES public.requirement_specs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_spec_versions
    ADD CONSTRAINT requirement_spec_versions_working_copy_id_fkey FOREIGN KEY (working_copy_id) REFERENCES public.requirement_specs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.requirement_specs
    ADD CONSTRAINT requirement_specs_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.requirement_specs
    ADD CONSTRAINT requirement_specs_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.requirement_specs
    ADD CONSTRAINT requirement_specs_status_id_fkey FOREIGN KEY (status_id) REFERENCES public.requirement_spec_statuses(id);

--
--

ALTER TABLE ONLY public.requirement_specs
    ADD CONSTRAINT requirement_specs_type_id_fkey FOREIGN KEY (type_id) REFERENCES public.requirement_spec_types(id);

--
--

ALTER TABLE ONLY public.requirement_specs
    ADD CONSTRAINT requirement_specs_working_copy_id_fkey FOREIGN KEY (working_copy_id) REFERENCES public.requirement_specs(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.sepa_export
    ADD CONSTRAINT sepa_export_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.sepa_export_items
    ADD CONSTRAINT sepa_export_items_ap_id_fkey FOREIGN KEY (ap_id) REFERENCES public.ap(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.sepa_export_items
    ADD CONSTRAINT sepa_export_items_ar_id_fkey FOREIGN KEY (ar_id) REFERENCES public.ar(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.sepa_export_items
    ADD CONSTRAINT sepa_export_items_chart_id_fkey FOREIGN KEY (chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.sepa_export_items
    ADD CONSTRAINT sepa_export_items_sepa_export_id_fkey FOREIGN KEY (sepa_export_id) REFERENCES public.sepa_export(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.sepa_export_message_ids
    ADD CONSTRAINT sepa_export_message_ids_sepa_export_id_fkey FOREIGN KEY (sepa_export_id) REFERENCES public.sepa_export(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.shop_images
    ADD CONSTRAINT shop_images_file_id_fkey FOREIGN KEY (file_id) REFERENCES public.files(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.shop_order_items
    ADD CONSTRAINT shop_order_items_shop_order_id_fkey FOREIGN KEY (shop_order_id) REFERENCES public.shop_orders(id) ON DELETE CASCADE;

--
--

ALTER TABLE ONLY public.shop_orders
    ADD CONSTRAINT shop_orders_kivi_customer_id_fkey FOREIGN KEY (kivi_customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.shop_orders
    ADD CONSTRAINT shop_orders_shop_id_fkey FOREIGN KEY (shop_id) REFERENCES public.shops(id);

--
--

ALTER TABLE ONLY public.shop_parts
    ADD CONSTRAINT shop_parts_part_id_fkey FOREIGN KEY (part_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.shop_parts
    ADD CONSTRAINT shop_parts_shop_id_fkey FOREIGN KEY (shop_id) REFERENCES public.shops(id);

--
--

ALTER TABLE ONLY public.stocktakings
    ADD CONSTRAINT stocktakings_bin_id_fkey FOREIGN KEY (bin_id) REFERENCES public.bin(id);

--
--

ALTER TABLE ONLY public.stocktakings
    ADD CONSTRAINT stocktakings_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.stocktakings
    ADD CONSTRAINT stocktakings_inventory_id_fkey FOREIGN KEY (inventory_id) REFERENCES public.inventory(id);

--
--

ALTER TABLE ONLY public.stocktakings
    ADD CONSTRAINT stocktakings_parts_id_fkey FOREIGN KEY (parts_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.stocktakings
    ADD CONSTRAINT stocktakings_warehouse_id_fkey FOREIGN KEY (warehouse_id) REFERENCES public.warehouse(id);

--
--

ALTER TABLE ONLY public.tax
    ADD CONSTRAINT tax_chart_id_fkey FOREIGN KEY (chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.tax
    ADD CONSTRAINT tax_skonto_purchase_chart_id_fkey FOREIGN KEY (skonto_purchase_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.tax
    ADD CONSTRAINT tax_skonto_sales_chart_id_fkey FOREIGN KEY (skonto_sales_chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.taxkeys
    ADD CONSTRAINT taxkeys_chart_id_fkey FOREIGN KEY (chart_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.taxkeys
    ADD CONSTRAINT taxkeys_tax_id_fkey FOREIGN KEY (tax_id) REFERENCES public.tax(id);

--
--

ALTER TABLE ONLY public.taxzone_charts
    ADD CONSTRAINT taxzone_charts_buchungsgruppen_id_fkey FOREIGN KEY (buchungsgruppen_id) REFERENCES public.buchungsgruppen(id);

--
--

ALTER TABLE ONLY public.taxzone_charts
    ADD CONSTRAINT taxzone_charts_expense_accno_id_fkey FOREIGN KEY (expense_accno_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.taxzone_charts
    ADD CONSTRAINT taxzone_charts_income_accno_id_fkey FOREIGN KEY (income_accno_id) REFERENCES public.chart(id);

--
--

ALTER TABLE ONLY public.taxzone_charts
    ADD CONSTRAINT taxzone_charts_taxzone_id_fkey FOREIGN KEY (taxzone_id) REFERENCES public.tax_zones(id);

--
--

ALTER TABLE ONLY public.time_recording_articles
    ADD CONSTRAINT time_recording_articles_part_id_fkey FOREIGN KEY (part_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.time_recordings
    ADD CONSTRAINT time_recordings_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customer(id);

--
--

ALTER TABLE ONLY public.time_recordings
    ADD CONSTRAINT time_recordings_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.time_recordings
    ADD CONSTRAINT time_recordings_order_id_fkey FOREIGN KEY (order_id) REFERENCES public.oe(id);

--
--

ALTER TABLE ONLY public.time_recordings
    ADD CONSTRAINT time_recordings_part_id_fkey FOREIGN KEY (part_id) REFERENCES public.parts(id);

--
--

ALTER TABLE ONLY public.time_recordings
    ADD CONSTRAINT time_recordings_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.project(id);

--
--

ALTER TABLE ONLY public.time_recordings
    ADD CONSTRAINT time_recordings_staff_member_id_fkey FOREIGN KEY (staff_member_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.todo_user_config
    ADD CONSTRAINT todo_user_config_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);

--
--

ALTER TABLE ONLY public.translation
    ADD CONSTRAINT translation_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.units_language
    ADD CONSTRAINT units_language_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.units_language
    ADD CONSTRAINT units_language_unit_fkey FOREIGN KEY (unit) REFERENCES public.units(name);

--
--

ALTER TABLE ONLY public.vendor
    ADD CONSTRAINT vendor_business_id_fkey FOREIGN KEY (business_id) REFERENCES public.business(id);

--
--

ALTER TABLE ONLY public.vendor
    ADD CONSTRAINT vendor_currency_id_fkey FOREIGN KEY (currency_id) REFERENCES public.currencies(id);

--
--

ALTER TABLE ONLY public.vendor
    ADD CONSTRAINT vendor_delivery_term_id_fkey FOREIGN KEY (delivery_term_id) REFERENCES public.delivery_terms(id);

--
--

ALTER TABLE ONLY public.vendor
    ADD CONSTRAINT vendor_language_id_fkey FOREIGN KEY (language_id) REFERENCES public.language(id);

--
--

ALTER TABLE ONLY public.vendor
    ADD CONSTRAINT vendor_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payment_terms(id);

--
--

ALTER TABLE ONLY public.vendor
    ADD CONSTRAINT vendor_taxzone_id_fkey FOREIGN KEY (taxzone_id) REFERENCES public.tax_zones(id);

--
--

ALTER TABLE ONLY tax.report_headings
    ADD CONSTRAINT report_headings_category_id_fkey FOREIGN KEY (category_id) REFERENCES tax.report_categories(id);

--
--

ALTER TABLE ONLY tax.report_variables
    ADD CONSTRAINT report_variables_heading_id_fkey FOREIGN KEY (heading_id) REFERENCES tax.report_headings(id);

--
--

